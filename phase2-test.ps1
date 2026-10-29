# Run in a SECOND PowerShell window while `php artisan serve` is running.
$base = "http://localhost:8000/api/v1"
$h = @{ Accept = "application/json" }

$sec   = Read-Host "Password for records@example.com (same as DTS_ADMIN_PASSWORD)" -AsSecureString
$plain = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
$login = Invoke-RestMethod -Method Post -Uri "$base/auth/login" -Headers $h -Body @{ email = "records@example.com"; password = $plain }
$token = $login.data.token
$h.Authorization = "Bearer $token"
$office = (Invoke-RestMethod -Uri "$base/me" -Headers $h).data.offices[0]
"Logged in. Office id = $office"

"`n--- 1. Register a document (with Idempotency-Key) ---"
$body = @{
  title = "Request for Certification"; document_type_code = "CERT_REQUEST"; direction = "incoming"
  sender_name = "Example Sender"; recipient_name = "Records Office"; priority = "normal"
  classification = "internal"; origin_office_id = $office; due_at = "2026-11-05T09:00:00+08:00"
  tags = @("test", "cert")
} | ConvertTo-Json
$h2 = $h.Clone(); $h2["Idempotency-Key"] = [guid]::NewGuid().ToString()
$doc = Invoke-RestMethod -Method Post -Uri "$base/documents" -Headers $h2 -ContentType "application/json" -Body $body
$doc.data | Format-List

"--- 2. Retry with the SAME key: must return the same document, not create a new one ---"
$again = Invoke-RestMethod -Method Post -Uri "$base/documents" -Headers $h2 -ContentType "application/json" -Body $body
"Same id returned: " + ($again.data.id -eq $doc.data.id)

"`n--- 3. Upload a file ---"
$id  = $doc.data.id
$tmp = Join-Path $env:TEMP "dts-test.txt"
"hello dts" | Out-File $tmp -Encoding ascii
curl.exe -s -X POST -H "Accept: application/json" -H "Authorization: Bearer $token" -F "file=@$tmp" "$base/documents/$id/files"

"`n`n--- 4. Search + detail ---"
(Invoke-RestMethod -Uri "$base/documents?q=certification&per_page=5" -Headers $h) | ConvertTo-Json -Depth 5
$detail = Invoke-RestMethod -Uri "$base/documents/$id" -Headers $h
$detail.data | ConvertTo-Json -Depth 5

"`n--- 5. Download the file ---"
$fileId = @($detail.data.files)[0].id
$out = Join-Path $env:TEMP "dts-download.txt"
Invoke-WebRequest -Uri "$base/documents/$id/files/$fileId/download" -Headers $h -OutFile $out
"Downloaded content: " + (Get-Content $out)

"`n--- 6. History ---"
(Invoke-RestMethod -Uri "$base/documents/$id/history" -Headers $h) | ConvertTo-Json -Depth 5

"`n--- 7. Unknown / out-of-scope ID must look like 'not found' ---"
try { Invoke-RestMethod -Uri "$base/documents/999999" -Headers $h } catch { "Got HTTP " + $_.Exception.Response.StatusCode.value__ }
