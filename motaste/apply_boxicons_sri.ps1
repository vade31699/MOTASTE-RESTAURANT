$ErrorActionPreference='Stop'
$q=[char]34

# 1. canonical boxicons bytes, downloaded fresh (already exist in cache from 3x-match earlier)
$cache = Join-Path (Join-Path $env:TEMP 'opencode') 'sri_canon'
$intFile = Join-Path $cache 'boxicons.min.css'
if (-not (Test-Path $intFile)) {
    New-Item -ItemType Directory -Force -Path $cache | Out-Null
    Invoke-WebRequest -Uri 'https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' -OutFile $intFile -UseBasicParsing
}
$bytes = [IO.File]::ReadAllBytes($intFile)
$int = 'sha384-' + [Convert]::ToBase64String([Security.Cryptography.SHA384]::Create().ComputeHash($bytes))
'canonical boxicons integrity: ' + $int

# 2. for each file, exactly one boxicons link presence
$files = @(
  @{ F='public\home.html';  U='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' },
  @{ F='public\staff.html'; U='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' }
)

# 3. verify canonical bytes are unchanged from unpkg by re-downloading and comparing
$tmp2 = Join-Path $cache 'verify_dl'
Invoke-WebRequest -Uri 'https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' -OutFile $tmp2 -UseBasicParsing
$int2 = 'sha384-' + [Convert]::ToBase64String([Security.Cryptography.SHA384]::Create().ComputeHash([IO.File]::ReadAllBytes($tmp2)))
if ($int2 -cne $int) { throw 'canonical bytes changed between downloads (unpkg served different content)' }
'download-to-download byte-stability check: MATCH (' + $int + ')'

# 4. programmatic retail: replace integrity attrs in the boxicons link tag of both html files
foreach ($t in $files) {
    $p   = (Resolve-Path $t.F).ProviderPath
    $url = $t.U
    $html = [IO.File]::ReadAllText($p)

    $rx = '(?is)(<link\b[^>]*?\bhref=' + $q + [regex]::Escape($url) + $q + '[^>]*?)(\s+integrity=' + $q + 'sha384-[^' + $q + ']*' + $q + ')?([^>]*?>?>)'
    # simpler: match the whole tag
    $tagRx = '<link\b[^>]*?\bhref=' + $q + [regex]::Escape($url) + $q + '[^>]*?>'
    $m = [regex]::Match($html, $tagRx)
    if (-not $m.Success) { throw ('boxicons link not found: ' + $t.F) }
    $tag = $m.Value

    # strip any existing integrity/crossorigin, then rebuild canonical
    $clean = [regex]::Replace($tag, '\s+integrity=' + $q + '[^' + $q + ']*' + $q, '')
    $clean = [regex]::Replace($clean, '\s+crossorigin=' + $q + '[^' + $q + ']*' + $q, '')
    $clean = $clean -replace '/\s*>\s*$', '>'
    $new = $clean.TrimEnd('>') + ' integrity=' + $q + $int + $q + ' crossorigin=' + $q + 'anonymous' + $q + '>'

    $html2 = $html.Replace($tag, $new)
    if ($html2 -ceq $html) { throw ('no replacement occurred: ' + $t.F) }
    [IO.File]::WriteAllText($p, $html2)
    'PATCHED ' + $t.F
}

# 5. verify: extract integrity from each file now, compare to canonical (no transcription)
foreach ($t in $files) {
    $p = (Resolve-Path $t.F).ProviderPath
    $html = [IO.File]::ReadAllText($p)
    $rx = '\bintegrity=' + $q + '(sha384-[^' + $q + ']*)' + $q
    $m = [regex]::Match($html, $rx)
    if (-not $m.Success) { throw ('no integrity in ' + $t.F) }
    $val = $m.Groups[1].Value
    $ok  = ($val -ceq $int)
    ($(if($ok){'OK  '}else{'FAIL'}) + ' ' + $t.F + '  in-file=' + $val)
    if (-not $ok) { throw ('integrity mismatch in ' + $t.F) }
}
'BOXICONS SRI: ALL FILES VERIFIED against canonical bytes. Done.'
