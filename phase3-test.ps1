# Run in a SECOND PowerShell window while `php artisan serve` is running.
$ErrorActionPreference = 'Stop'
$base = "http://localhost:8000/api/v1"
$script:failed = 0

function Call([string]$Method, [string]$Path, $H, $Body = $null) {
  $p = @{ Method = $Method; Uri = "$base$Path"; Headers = $H; UseBasicParsing = $true }
  if ($null -ne $Body) { $p.Body = ($Body | ConvertTo-Json -Depth 6); $p.ContentType = "application/json" }
  try {
    $r = Invoke-WebRequest @p
    $json = $null
    if ($r.Content) { $json = $r.Content | ConvertFrom-Json }
    return [pscustomobject]@{ Status = [int]$r.StatusCode; Json = $json }
  } catch {
    $resp = $_.Exception.Response
    $status = 0; $text = ""
    if ($resp) {
      $status = [int]$resp.StatusCode
      $sr = New-Object IO.StreamReader($resp.GetResponseStream()); $text = $sr.ReadToEnd()
    }
    $json = $null
    try { $json = $text | ConvertFrom-Json } catch {}
    return [pscustomobject]@{ Status = $status; Json = $json }
  }
}

function Check([string]$Label, $Res, [int]$Expect, [string]$Code = "") {
  $ok = ($Res.Status -eq $Expect)
  if ($ok -and $Code -ne "") { $ok = ($Res.Json.error.code -eq $Code) }
  $mark = if ($ok) { "PASS" } else { "FAIL" }
  $extra = ""
  if ($Res.Json -and $Res.Json.error) { $extra = " " + $Res.Json.error.code }
  "{0}  {1}  (HTTP {2}{3})" -f $mark, $Label, $Res.Status, $extra
  if (-not $ok) {
    $script:failed++
    if ($Res.Json) { "      " + ($Res.Json | ConvertTo-Json -Depth 5 -Compress) }
  }
}

function Login([string]$Email, [string]$Pw) {
  $r = Call "POST" "/auth/login" @{ Accept = "application/json" } @{ email = $Email; password = $Pw }
  if ($r.Status -ne 200) { throw "Login failed for $Email (HTTP $($r.Status)). Run set-dev-passwords.ps1 first." }
  return @{ Accept = "application/json"; Authorization = "Bearer $($r.Json.data.token)" }
}

function Doc($id, $H) { return (Call "GET" "/documents/$id" $H).Json.data }

$sec = Read-Host "Password used for the dev users" -AsSecureString
$pw  = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
$rec   = Login "records@example.com" $pw
$head  = Login "head@example.com" $pw
$staff = Login "staff@example.com" $pw

$headMe = (Call "GET" "/me" $head).Json.data
$recMe  = (Call "GET" "/me" $rec).Json.data
$mainOffice  = @($recMe.offices)[0]
$adminOffice = @($headMe.offices)[0]
"Offices: MAIN=$mainOffice  ADMIN=$adminOffice   head user id=$($headMe.id)"
$due = (Get-Date).AddDays(3).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")

"`n=== A. Register and route ==="
$reg = Call "POST" "/documents" $rec @{ title = "Phase 3 workflow test"; document_type_code = "MEMO"; direction = "internal"; priority = "high"; classification = "internal"; origin_office_id = $mainOffice }
Check "records registers a document" $reg 201
$id = $reg.Json.data.id

$routeBody = @{ to_office_id = $adminOffice; to_user_id = $headMe.id; action_required = "Approval"; instructions = "Please review and decide"; due_at = $due }
$r1 = Call "POST" "/documents/$id/routes" $rec $routeBody
Check "records routes it to ADMIN office, addressed to head" $r1 201
$route1 = $r1.Json.data.id
$d = Doc $id $rec
"      document status: $($d.status) (expect routed); custody office: $($d.current_office_id) (expect ${mainOffice}; custody moves only on acknowledgement)"

Check "a second route is blocked while one is active" (Call "POST" "/documents/$id/routes" $rec $routeBody) 409 "ROUTE_ACTIVE"
Check "staff (same office, not the addressee) can see the routed document" (Call "GET" "/documents/$id" $staff) 200
Check "staff cannot acknowledge a route addressed to head" (Call "POST" "/routes/$route1/acknowledge" $staff) 403 "NOT_RECIPIENT"

$inbox = Call "GET" "/routes?box=inbox" $head
Check "head's inbox lists the route" $inbox 200
"      inbox items: $(@($inbox.Json.data).Count)"
Check "head cannot decide before acknowledging" (Call "POST" "/documents/$id/decisions" $head @{ decision = "approved" }) 409 "NO_ACTIVE_ROUTE"

"`n=== B. Acknowledge, comment, return for correction ==="
Check "head acknowledges (hand-off complete)" (Call "POST" "/routes/$route1/acknowledge" $head) 200
$d = Doc $id $rec
"      document status: $($d.status) (expect in_review); custody office: $($d.current_office_id) (expect $adminOffice)"
Check "head adds a comment" (Call "POST" "/documents/$id/comments" $head @{ body = "Section 2 needs the signed attachment." }) 201
Check "return without a reason is rejected" (Call "POST" "/documents/$id/decisions" $head @{ decision = "returned" }) 422 "VALIDATION_ERROR"
Check "head returns it for correction (with reason)" (Call "POST" "/documents/$id/decisions" $head @{ decision = "returned"; reason = "Attach the signed form first." }) 201
$d = Doc $id $rec
"      document status: $($d.status) (expect returned); custody office: $($d.current_office_id) (expect $mainOffice, back with sender)"

"`n=== C. Re-route and approve ==="
$r2 = Call "POST" "/documents/$id/routes" $rec $routeBody
Check "records re-routes the returned document" $r2 201
$route2 = $r2.Json.data.id
Check "head acknowledges the second route" (Call "POST" "/routes/$route2/acknowledge" $head) 200
Check "head approves" (Call "POST" "/documents/$id/decisions" $head @{ decision = "approved" }) 201
$d = Doc $id $rec
"      document status: $($d.status) (expect approved); custody office: $($d.current_office_id) (expect $mainOffice)"

"`n=== D. Release, close, lock, reopen ==="
Check "head has no release permission" (Call "POST" "/documents/$id/release" $head @{}) 403 "FORBIDDEN"
Check "records releases the approved document" (Call "POST" "/documents/$id/release" $rec @{ note = "Handed to courier" }) 200
Check "records closes it" (Call "POST" "/documents/$id/close" $rec @{}) 200
Check "closed documents are read-only (Phase 2 rule)" (Call "PATCH" "/documents/$id" $rec @{ title = "changed" }) 409 "DOCUMENT_LOCKED"
Check "reopen without a reason is rejected" (Call "POST" "/documents/$id/reopen" $rec @{}) 422 "VALIDATION_ERROR"
Check "records reopens with a reason" (Call "POST" "/documents/$id/reopen" $rec @{ reason = "Wrong recipient on the memo." }) 200

"`n=== E. Forward and cancel (new document) ==="
$reg2 = Call "POST" "/documents" $rec @{ title = "Forward and cancel test"; document_type_code = "LETTER"; direction = "incoming"; origin_office_id = $mainOffice }
$id2 = $reg2.Json.data.id
$ra = Call "POST" "/documents/$id2/routes" $rec @{ to_office_id = $adminOffice; action_required = "Review" }
Check "route to the whole ADMIN office (no named person)" $ra 201
$routeA = $ra.Json.data.id
Check "records cancels it before anyone acknowledges" (Call "POST" "/routes/$routeA/cancel" $rec @{ reason = "Sent to the wrong office." }) 200
"      document status: $((Doc $id2 $rec).status) (expect registered, restored)"
$rb = Call "POST" "/documents/$id2/routes" $rec @{ to_office_id = $adminOffice; action_required = "Review" }
$routeB = $rb.Json.data.id
Check "staff claims the office-level route by acknowledging it" (Call "POST" "/routes/$routeB/acknowledge" $staff) 200
Check "staff forwards it on to head" (Call "POST" "/routes/$routeB/forward" $staff @{ to_office_id = $adminOffice; to_user_id = $headMe.id; action_required = "Approval" }) 201

"`n=== F. Timeline and history ==="
$tl = Call "GET" "/documents/$id/routes" $rec
Check "custody timeline" $tl 200
foreach ($rt in @($tl.Json.data)) { "      route $($rt.id) [$($rt.status)]: " + ((@($rt.events) | ForEach-Object { $_.event_type }) -join " -> ") }
$hist = Call "GET" "/documents/$id/history" $rec
Check "audit history" $hist 200
"      " + ((@($hist.Json.data) | ForEach-Object { $_.action }) -join ", ")

""
if ($script:failed -eq 0) { "ALL CHECKS PASSED" } else { "$($script:failed) CHECK(S) FAILED. Paste the FAIL lines above." }

