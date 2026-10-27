from pathlib import Path
import zipfile,json,shutil
root=Path(__file__).resolve().parent
release=root/'deployment/review-r1'
base="""<?php
declare(strict_types=1);
$webRoot=realpath($_SERVER['DOCUMENT_ROOT']??'');
$privateRoot=$webRoot?realpath(dirname($webRoot).'/dakshayani-residential-live'):false;
if(!$webRoot||!$privateRoot||str_starts_with(strtolower(str_replace(chr(92),'/',$privateRoot)).'/',strtolower(str_replace(chr(92),'/',$webRoot)).'/')){http_response_code(503);exit('Configuration unavailable');}
require_once $webRoot.'/includes/auth.php';start_session();
if(($_SESSION['user']['role_name']??'')!=='admin'){header('Location: /login.php');exit;}
require_admin();
putenv('RES_DATA='.$privateRoot.'/qa-runtime/hosted');putenv('RES_MODE=preview');putenv('RES_MAIL=0');
"""
bootstrap={}
for p in release.rglob('*'):
 if p.is_file() and p.name!='manifest.json' and ('/public_html/solar-enquiry/' not in p.as_posix() or p.name=='intake.php'):
  bootstrap[p.relative_to(release).as_posix()]=p.read_bytes()
bootstrap['dakshayani-residential-live/release-config.php']=b"<?php\nreturn ['ready'=>false,'mail_enabled'=>false];\n"
bootstrap['public_html/residential-qa.php']=(base+"define('RES_STAFF_AUTHENTICATED',true);require $privateRoot.'/src/staff.php';\n").encode()
html=(root/'public/solar-enquiry/index.html').read_text(encoding='utf-8-sig').replace('"assets/','"/solar-enquiry/assets/')
bootstrap['public_html/residential-qa-enquiry/index.php']=(base+"header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');readfile(__DIR__.'/page.html');\n").encode()
bootstrap['public_html/residential-qa-enquiry/.htaccess']=b'Options -Indexes\nDirectoryIndex index.php\n<Files "page.html">\nRequire all denied\n</Files>\n'
bootstrap['public_html/residential-qa-enquiry/page.html']=html.encode()
bootstrap['public_html/residential-qa-enquiry/intake.php']=(base+"session_write_close();require $privateRoot.'/src/api.php';\n").encode()
for name in ['app.js','language.js','pricing.js','style.css','privacy.html']:bootstrap['public_html/residential-qa-enquiry/'+name]=(root/'public/solar-enquiry'/name).read_bytes()
for name in ['core_check.php','proposal_check.php','concurrent_worker.php']:bootstrap['dakshayani-residential-live/tests/'+name]=(root/'tests'/name).read_bytes()
activation={p.relative_to(release).as_posix():p.read_bytes() for p in (release/'public_html/solar-enquiry').glob('*') if p.is_file()}
activation['dakshayani-residential-live/release-config.php']=b"<?php\nreturn ['ready'=>true,'mail_enabled'=>true];\n"
def package(name,items):
 with zipfile.ZipFile(root/'deployment'/name,'w',zipfile.ZIP_DEFLATED) as z:
  for path,data in items.items():
   assert '/runtime/' not in path and 'sales.json' not in path
   info=zipfile.ZipInfo(path);info.create_system=3;info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED;z.writestr(info,data)
package('Residential_bootstrap_20261027.zip',bootstrap)
package('Residential_activate_20261027.zip',activation)
print('Prepared separate bootstrap and activation archives. No customer records included.')
