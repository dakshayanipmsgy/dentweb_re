from pathlib import Path
import json,re,shutil,zipfile,hashlib
root=Path(__file__).resolve().parent
book=json.loads((root/'config/pricebook.json').read_text(encoding='utf-8-sig'))
pricing=root/'public/solar-enquiry/pricing.js'
js=pricing.read_text(encoding='utf-8-sig')
js=re.sub(r'const SOLAR_PLANS = .*?\n};',lambda m:'const SOLAR_PLANS = '+json.dumps(book['plans'],ensure_ascii=False,indent=1)+';',js,count=1,flags=re.S)
# Never assume a hybrid or mixed package qualifies for CFA.
js=js.replace('subsidy=nonDcr?0:',"subsidy=(nonDcr||plan.code.startsWith('H'))?0:")
pricing.write_text(js,encoding='utf-8')
out=root/'deployment/review-r1'; private=out/'dakshayani-residential-live'; public=out/'public_html'
(private/'src').mkdir(parents=True,exist_ok=True);(private/'config').mkdir(exist_ok=True);public.mkdir(parents=True,exist_ok=True)
for p in (root/'src').glob('*.php'):shutil.copy2(p,private/'src'/p.name)
shutil.copy2(root/'config/pricebook.json',private/'config/pricebook.json')
shutil.copy2(root/'notify-worker.php',private/'notify-worker.php')
shutil.copy2(root/'import-portal.php',private/'import-portal.php')
(private/'.htaccess').write_text('Require all denied\n',encoding='utf-8')
(private/'release-config.example.php').write_text("<?php\nreturn ['ready'=>false,'mail_enabled'=>false];\n",encoding='utf-8')
common="""<?php
declare(strict_types=1);
$webRoot=realpath($_SERVER['DOCUMENT_ROOT']??'');
$privateRoot=$webRoot?realpath(dirname($webRoot).'/dakshayani-residential-live'):false;
if(!$webRoot||!$privateRoot||str_starts_with(strtolower(str_replace(chr(92),'/',$privateRoot)).'/',strtolower(str_replace(chr(92),'/',$webRoot)).'/')){http_response_code(503);exit('Enquiry service unavailable. Call 7070278178.');}
putenv('RES_DATA='.$privateRoot.'/runtime');
"""
auth="""require_once $webRoot.'/includes/auth.php';
start_session();
if(($_SESSION['user']['role_name']??'')!=='admin'){header('Location: /login.php');exit;}
require_admin();
"""
landing=public/'solar-enquiry';landing.mkdir(exist_ok=True)
for name in ['index.html','app.js','language.js','style.css','pricing.js','privacy.html']:shutil.copy2(root/'public/solar-enquiry'/name,landing/name)
# Existing photographic assets stay in place; they are deliberately not replaced.
(landing/'intake.php').write_text(common+"""$release=is_file($privateRoot.'/release-config.php')?require $privateRoot.'/release-config.php':[];
if(empty($release['ready'])){http_response_code(503);header('Content-Type: application/json');exit(json_encode(['error'=>'Enquiry service is being checked. Please call 7070278178.']));}
putenv('RES_MODE=live');putenv('RES_MAIL=0');
require $privateRoot.'/src/api.php';
""",encoding='utf-8')
(public/'residential-enquiries.php').write_text(common+auth+"putenv('RES_MODE=live');\nputenv('RES_MAIL=0');\ndefine('RES_STAFF_AUTHENTICATED',true);\nrequire $privateRoot.'/src/staff.php';\n",encoding='utf-8')
assets=public/'residential-assets';assets.mkdir(exist_ok=True);shutil.copy2(root/'public/residential-assets/desk.css',assets/'desk.css')
files=sorted(p for p in out.rglob('*') if p.is_file() and p.name!='manifest.json')
manifest=[dict(path=p.relative_to(out).as_posix(),sha256=hashlib.sha256(p.read_bytes()).hexdigest()) for p in files]
(out/'manifest.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
archive=root/'deployment/Dakshayani_Residential_REVIEW_r1.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
 for p in out.rglob('*'):
  if p.is_file():
   assert '/runtime/' not in p.as_posix() and p.name!='release-config.php'
   z.write(p,p.relative_to(out))
print(f'Prepared, NOT uploaded: {archive}')
