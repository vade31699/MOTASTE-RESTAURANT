$ErrorActionPreference='Stop'
$q=[char]34
$ProgressPreference='SilentlyContinue'
$tmp=Join-Path $env:TEMP 'opencode\boxi_fix4'
New-Item -ItemType Directory -Force -Path $tmp|Out-Null
$canon=Join-Path $tmp 'boxicons.min.css'

if(-not(Test-Path $canon)){
  $r=Invoke-WebRequest -Uri 'https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' -UseBasicParsing
  [IO.File]::WriteAllBytes($canon,$r.Content)
}
$b=[IO.File]::ReadAllBytes($canon)
$int='sha384-'+[Convert]::ToBase64String([Security.Cryptography.SHA384]::Create().ComputeHash($b))
$canonSize=([IO.File]::ReadAllBytes($canon)).Length

foreach($f in @('public\home.html','public\staff.html')){
  $p=(Resolve-Path $f).ProviderPath
  $html=[IO.File]::ReadAllText($p)
  $u='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css'
  $ue=[regex]::Escape($u)
  $qr='<link\b[^>]*?\bhref='+$q+$ue+$q+'[^>]*?>'
  $m=[regex]::Match($html,$qr)
  if(-not $m.Success){throw 'boxicons link not found in '+$f}
  $old=$m.Value
  $new='<link rel='+$q+'stylesheet'+$q+' href='+$q+$u+$q+' integrity='+$q+$int+$q+' crossorigin='+$q+'anonymous'+$q+'>'
  $html2=$html.Replace($old,$new)
  if($html2 -ceq $html){throw 'no change: '+$f}
  [IO.File]::WriteAllText($p,$html2)
  'patched '+$f
}

'--- verify (boolean, from byte-canonical recompute in-process) ---'
$allOk=$true
foreach($f in @('public\home.html','public\staff.html')){
  $p=(Resolve-Path $f).ProviderPath
  $html=[IO.File]::ReadAllText($p)
  $u='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css'
  $ue=[regex]::Escape($u)
  $qr='<link\b[^>]*?\bhref='+$q+$ue+$q+'[^>]*?integrity='+$q+'(sha384-[^'+$q+']*)'+$q+'[^>]*?>'
  $mm=[regex]::Match($html,$qr)
  if(-not $mm.Success){'FAIL tag-not-found '+$f;$allOk=$false;continue}
  $inF=$mm.Groups[1].Value
  $ok=($inF -ceq $int)
  if(-not $ok){
    'FAIL '+$f+' integrity-mismatch (in-file vs canonical on-disk bytes)'
    $allOk=$false
  } else {
    'OK   '+$f+' boxicons integrity matches canonical bytes ('+$canonSize+' bytes canonical)'
  }
}
if($allOk){'RESULT: boxicons SRI valid in BOTH files'}
else{throw 'boxicons SRI verification FAILED'}
