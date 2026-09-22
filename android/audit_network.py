#!/usr/bin/env python3
"""Статический аудит адресов и сетевых проверок. Не является доказательством отсутствия egress."""
import argparse
import json
import re
from collections import Counter
from pathlib import Path
from urllib.parse import urlsplit

p = argparse.ArgumentParser()
p.add_argument('decoded', type=Path)
p.add_argument('--output', required=True, type=Path)
args = p.parse_args()
hosts = Counter()
checks = []
references = []
for file in args.decoded.rglob('*'):
    if not file.is_file() or file.suffix not in {'.smali', '.json', '.xml', '.properties'}:
        continue
    text = file.read_text(errors='replace').replace(r'\/', '/')
    relative = str(file.relative_to(args.decoded))
    for url in set(re.findall(r'https?://[^\s"<>\\]+', text)):
        try:
            host = urlsplit(url).hostname
        except ValueError:
            continue
        if host:
            hosts[host] += 1
            if any(x in host for x in ('x-diag', 'xdiagpro', '79.174.70.')):
                references.append({'file': relative, 'url': url})
    if file.suffix == '.smali' and '/com/xdiagpro/' in relative:
        for method in re.findall(r'(?ms)^\.method .*?^\.end method', text):
            if any(x in method for x in ('getActiveNetwork', 'hasCapability(I)', 'isConnected()Z', 'isAvailable()Z', 'getAllNetworks', 'www.google.com', 'www.baidu')):
                checks.append({'file': relative, 'method': method})
report = {'hosts': dict(hosts.most_common()), 'references': references, 'network_checks': checks,
          'limitations': 'Native libraries, composed URLs, DNS/TCP and runtime-loaded code require device/network tracing.'}
args.output.write_text(json.dumps(report, ensure_ascii=False, indent=2))
print('NETWORK_HOSTS ' + json.dumps(report['hosts']))
print('NETWORK_REFERENCES ' + json.dumps(references)[:24000])
for entry in checks:
    print('NETWORK_CHECK ' + json.dumps(entry)[:12000])
