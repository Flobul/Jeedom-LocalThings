#!/usr/bin/env python3
"""Private pipe RPC for OCF DTLS PSK. No credentials in argv, environment or logs."""
import base64
import ctypes as C
import ctypes.util
import ipaddress
import json
import select
import socket
import sys
import time

CIPHER = b'ECDHE-PSK-AES128-CBC-SHA256:@SECLEVEL=0'


class Transport:
    def __init__(self, host, port, source_port, identity, key):
        ipaddress.IPv4Address(host)
        if len(identity) != 16 or b'\0' in identity or len(key) != 16:
            raise ValueError('invalid credential')
        if not 1 <= port <= 65535 or not 0 <= source_port <= 65535:
            raise ValueError('invalid port')
        self.lib = C.CDLL(ctypes.util.find_library('ssl'))
        p, i, u, l = C.c_void_p, C.c_int, C.c_uint, C.c_long
        signatures = {
            'DTLS_client_method': (p, []), 'SSL_CTX_new': (p, [p]),
            'SSL_CTX_free': (None, [p]), 'SSL_new': (p, [p]), 'SSL_free': (None, [p]),
            'SSL_CTX_set_cipher_list': (i, [p, C.c_char_p]),
            'SSL_CTX_ctrl': (l, [p, i, l, p]), 'SSL_ctrl': (l, [p, i, l, p]),
            'SSL_set_fd': (i, [p, i]), 'SSL_connect': (i, [p]),
            'SSL_get_error': (i, [p, i]), 'SSL_read': (i, [p, p, i]),
            'SSL_write': (i, [p, p, i]), 'SSL_pending': (i, [p]),
        }
        for name, (result, args) in signatures.items():
            fn = getattr(self.lib, name); fn.restype = result; fn.argtypes = args
        callback_type = C.CFUNCTYPE(u, p, p, p, u, p, u)
        def callback(ssl, hint, identity_out, identity_size, key_out, key_size):
            if not identity_out or not key_out or identity_size < 17 or key_size < len(key):
                return 0
            C.memmove(identity_out, identity + b'\0', 17)
            C.memmove(key_out, key, len(key))
            return len(key)
        self.callback = callback_type(callback)
        setter = self.lib.SSL_CTX_set_psk_client_callback
        setter.argtypes = [p, callback_type]; setter.restype = None
        self.ctx = self.lib.SSL_CTX_new(self.lib.DTLS_client_method())
        self.ssl = None; self.sock = None
        if not self.ctx: raise RuntimeError('context unavailable')
        try:
            if self.lib.SSL_CTX_set_cipher_list(self.ctx, CIPHER) != 1:
                raise RuntimeError('PSK cipher unavailable')
            # SSL_CTRL_SET_MIN/MAX_PROTO_VERSION: DTLS 1.2 only.
            for control in (123, 124):
                if self.lib.SSL_CTX_ctrl(self.ctx, control, 0xFEFD, None) != 1:
                    raise RuntimeError('DTLS 1.2 unavailable')
            setter(self.ctx, self.callback)
            self.ssl = self.lib.SSL_new(self.ctx)
            if not self.ssl: raise RuntimeError('session unavailable')
            self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            self.sock.bind(('0.0.0.0', source_port)); self.sock.connect((host, port))
            self.sock.setblocking(False)
            if self.lib.SSL_set_fd(self.ssl, self.sock.fileno()) != 1:
                raise RuntimeError('socket unavailable')
            self.lib.SSL_ctrl(self.ssl, 17, 1200, None)  # SSL_CTRL_SET_MTU
        except Exception:
            self.close(); raise

    def wait(self, result, deadline):
        error = self.lib.SSL_get_error(self.ssl, result)
        if error not in (2, 3): raise RuntimeError('DTLS PSK failed')
        remaining = deadline - time.monotonic()
        if remaining <= 0: raise TimeoutError('DTLS PSK timeout')
        select.select([self.sock] if error == 2 else [], [self.sock] if error == 3 else [], [], min(.1, remaining))
        # DTLS_CTRL_HANDLE_TIMEOUT triggers retransmission when OpenSSL's timer expires.
        if self.lib.SSL_ctrl(self.ssl, 74, 0, None) < 0:
            raise RuntimeError('DTLS retransmission failed')

    def connect(self, timeout):
        deadline = time.monotonic() + timeout
        while True:
            result = self.lib.SSL_connect(self.ssl)
            if result == 1: return
            self.wait(result, deadline)

    def write(self, data):
        if not data or len(data) > 65535: raise ValueError('invalid frame')
        buffer = C.create_string_buffer(data)
        deadline = time.monotonic() + 3
        while True:
            result = self.lib.SSL_write(self.ssl, buffer, len(data))
            if result == len(data): return
            if result > 0: raise RuntimeError('partial DTLS write')
            self.wait(result, deadline)

    def read(self, timeout):
        deadline = time.monotonic() + timeout
        buffer = C.create_string_buffer(65536)
        while True:
            result = self.lib.SSL_read(self.ssl, buffer, len(buffer))
            if result > 0: return buffer.raw[:result]
            try: self.wait(result, deadline)
            except TimeoutError: return None

    def close(self):
        if self.ssl: self.lib.SSL_free(self.ssl); self.ssl = None
        if self.ctx: self.lib.SSL_CTX_free(self.ctx); self.ctx = None
        if self.sock: self.sock.close(); self.sock = None


def main():
    transport = None
    try:
        while True:
            line = sys.stdin.buffer.readline(200000)
            if not line: break
            if not line.endswith(b'\n'): raise ValueError('oversized request')
            request = json.loads(line)
            action = request['action']
            result = None
            if action == 'connect':
                if transport: raise ValueError('already connected')
                transport = Transport(request['host'], int(request['port']), int(request['source_port']),
                                      bytes.fromhex(request['identity']), bytes.fromhex(request['key']))
                transport.connect(max(.1, min(30, float(request['timeout']))))
                request.clear()
            elif action == 'write': transport.write(base64.b64decode(request['data'], validate=True))
            elif action == 'read':
                data = transport.read(max(.01, min(30, float(request['timeout']))))
                result = None if data is None else base64.b64encode(data).decode('ascii')
            else: raise ValueError('invalid action')
            print(json.dumps({'ok': True, 'result': result}), flush=True)
    except Exception:
        # Exceptions from OpenSSL, JSON or credentials must never be rendered.
        print(json.dumps({'ok': False, 'error': 'Session OwnerPSK impossible ou interrompue'}), flush=True)
    finally:
        if transport: transport.close()


if __name__ == '__main__':
    if sys.argv[1:] == ['--check']:
        try:
            library = C.CDLL(ctypes.util.find_library('ssl'))
            for symbol in ('DTLS_client_method', 'SSL_CTX_set_psk_client_callback', 'SSL_CTX_ctrl'):
                getattr(library, symbol)
            print('OwnerPSK transport available')
        except Exception:
            print('OwnerPSK requires Python 3 and a libssl with DTLS PSK support')
            sys.exit(1)
    else:
        main()

