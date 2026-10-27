import urllib.request, urllib.error, http.cookiejar, json, uuid, re
base='http://127.0.0.1:8938'
jar=http.cookiejar.CookieJar(); c=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
token=json.load(c.open(base+'/solar-enquiry/intake.php'))['csrf']
a=dict(csrf=token,name='SYNTHETIC HTTP Homeowner',phone='9000000001',district='Ranchi',bill='1001-2500',consent='1',request_id=str(uuid.uuid4()),source='synthetic',campaign='http_qa')
def post(a): return c.open(urllib.request.Request(base+'/solar-enquiry/intake.php',json.dumps(a).encode(),headers={'Content-Type':'application/json'}))
try: post({**a,'csrf':'invalid'}); raise AssertionError('CSRF accepted')
except urllib.error.HTTPError as e: assert e.code==403
try: post({**a,'phone':'bad'}); raise AssertionError('invalid accepted')
except urllib.error.HTTPError as e: assert e.code==422
r=post(a); saved=json.load(r); assert r.status==201 and saved['mode']=='preview'
assert json.load(post(a))['reference']==saved['reference']
assert json.load(post(dict(csrf=token,action='handoff',reference=saved['reference'])))['recorded']=='click_only'
html=c.open(base+'/residential-enquiries.php?view=all').read().decode(); assert saved['reference'] in html and 'suppressed_preview' in html
record=re.search(r'<form method="post"><input type="hidden" name="csrf" value="([^"]+)"><input type="hidden" name="id" value="'+saved['reference']+r'"><input type="hidden" name="version" value="(\d+)"',html)
assert record
import urllib.parse
data=dict(csrf=record[1],id=saved['reference'],version=record[2],stage='contact_attempted',note='SYNTHETIC HTTP attempt recorded',owner='Vishesh Vardhan',next_action='TEST callback review',due_at='2026-10-28T10:00')
result=c.open(base+'/residential-enquiries.php',urllib.parse.urlencode(data).encode()).read().decode();assert 'Saved. No customer message sent.' in result; assert 'SYNTHETIC HTTP attempt recorded' in c.open(base+'/residential-enquiries.php?view=all').read().decode()
for path in ['/runtime/sales.json','/src/core.php','/../runtime/sales.json']:
 try: c.open(base+path);raise AssertionError('private exposed')
 except urllib.error.HTTPError as e: assert e.code==404
print('PASS HTTP: CSRF, phone validation, durable acceptance, retry, click-only handoff, staff outcome persistence, private-route denial. No external mail sent.')


