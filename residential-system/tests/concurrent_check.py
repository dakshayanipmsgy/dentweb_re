from pathlib import Path
import subprocess,concurrent.futures,json,uuid
root=Path(__file__).resolve().parents[1]
data=root/'qa-runtime'/('concurrent-'+uuid.uuid4().hex)
def work(i):
 p=subprocess.run(['C:/php/php.exe',str(root/'tests/concurrent_worker.php'),str(data),str(i)],capture_output=True,text=True,check=True)
 return json.loads(p.stdout)['reference']
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool: ids=list(pool.map(work,range(16)))
s=json.loads((data/'sales.json').read_text(encoding='utf-8'))
assert len(set(ids))==16 and len(s['leads'])==16 and len(s['requests'])==16
assert all(l['mode']=='preview' for l in s['leads'].values())
print('PASS 16 concurrent submissions: 16 intact records, no lost writes or overwritten enquiries, all preview-only.')
