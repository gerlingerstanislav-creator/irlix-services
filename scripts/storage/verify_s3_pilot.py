#!/usr/bin/env python3
"""Private synthetic S3 PUT/GET/DELETE smoke; no business objects or secrets logged."""
import datetime
import hashlib
import hmac
import re
import secrets
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


def read_env(path):
    result = {}
    for line in Path(path).read_text().splitlines():
        if not line or line.lstrip().startswith('#') or '=' not in line:
            continue
        k, val = line.split('=', 1)
        result[k] = val.strip().strip("'").strip('"')
    return result


def aws_signature(key, message):
    return hmac.new(key, message.encode('utf-8'), hashlib.sha256).digest()


def request(method, bucket, key, access, secret, content=b''):
    uri = '/' + urllib.parse.quote(bucket + '/' + key, safe='/-_.~')
    host = '127.0.0.1:8113'
    date = datetime.datetime.now(datetime.timezone.utc)
    timestamp = date.strftime('%Y%m%dT%H%M%SZ')
    day = date.strftime('%Y%m%d')
    payload_hash = hashlib.sha256(content).hexdigest()
    signed_headers = 'host;x-amz-content-sha256;x-amz-date'
    canonical_headers = f'host:{host}\nx-amz-content-sha256:{payload_hash}\nx-amz-date:{timestamp}\n'
    canonical_request = '\n'.join([method, uri, '', canonical_headers, signed_headers, payload_hash])
    scope = f'{day}/us-east-1/s3/aws4_request'
    to_sign = '\n'.join(['AWS4-HMAC-SHA256', timestamp, scope,
                         hashlib.sha256(canonical_request.encode()).hexdigest()])
    derived = ('AWS4' + secret).encode()
    for part in [day, 'us-east-1', 's3', 'aws4_request']:
        derived = aws_signature(derived, part)
    signature = hmac.new(derived, to_sign.encode(), hashlib.sha256).hexdigest()
    authorization = (f'AWS4-HMAC-SHA256 Credential={access}/{scope}, '
                     f'SignedHeaders={signed_headers}, Signature={signature}')
    req = urllib.request.Request(
        'http://' + host + uri, data=content if method == 'PUT' else None,
        method=method,
        headers={'Host': host, 'X-Amz-Date': timestamp,
                 'X-Amz-Content-Sha256': payload_hash, 'Authorization': authorization},
    )
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            return resp.status, resp.read()
    except urllib.error.HTTPError as exc:
        return exc.code, b''


def main():
    if len(sys.argv) != 2:
        raise SystemExit('Usage: verify_s3_pilot.py ROOT_ONLY_ENV')
    data = read_env(sys.argv[1])
    access, secret = (data.get('STORAGE_S3_ACCESS_KEY', ''),
                      data.get('STORAGE_S3_SECRET_KEY', ''))
    bucket = data.get('STORAGE_S3_BUCKET', 'irlix-storage-pilot')
    if not re.fullmatch('[a-z0-9][a-z0-9.-]{1,60}[a-z0-9]', bucket):
        raise SystemExit('Storage synthetic smoke: invalid bucket name')
    if len(access) < 12 or len(secret) < 32 or access == 'PROVISION_ON_SERVER':
        raise SystemExit('Storage synthetic smoke: server credentials missing')
    key = '.irlix-smoke/' + secrets.token_hex(14) + '.txt'
    payload = ('synthetic-storage-smoke:' + secrets.token_hex(24)).encode()
    wrote = False
    try:
        status, _ = request('PUT', bucket, key, access, secret, payload)
        assert status in (200, 201, 204), f'S3 PUT failed ({status})'
        wrote = True
        status, received = request('GET', bucket, key, access, secret)
        assert status == 200 and received == payload, f'S3 GET content mismatch ({status})'
    finally:
        if wrote:
            status, _ = request('DELETE', bucket, key, access, secret)
            if status not in (200, 204):
                raise AssertionError(f'S3 synthetic object cleanup failed ({status})')
    status, _ = request('GET', bucket, key, access, secret)
    assert status in (403, 404), f'S3 deleted synthetic object still accessible ({status})'
    print('[verify] Storage S3 signed synthetic PUT/GET/DELETE and checksummed content OK')


if __name__ == '__main__':
    main()
