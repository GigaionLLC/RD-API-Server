# Scratch input target for live input testing on Windows.
#
# Opens a focused, always-on-top window at a known position and records every mouse and
# keyboard event it receives as JSON lines. Injected input cannot be distinguished from
# physical input at the OS level, so the safe pattern is not to filter events but to
# CONTROL THE RECEIVER: aim everything inside a window that does nothing, and read back
# what actually arrived.
#
# This is the ground truth for input tests. The protocol offers no echo of what the peer
# injected, and the peer suppresses CursorPosition toward whoever sent input for 300ms,
# so without a target like this an input test can only prove "no error", not "correct".
#
#   powershell -File tools/win/input-target.ps1 -Log out.jsonl -X 400 -Y 200 -Seconds 90
#
# The window closes itself after -Seconds so a crashed test cannot leave it on screen.

param(
    [string]$Log = "$env:TEMP\web-client-input-target.jsonl",
    [int]$X = 400,
    [int]$Y = 200,
    [int]$Width = 900,
    [int]$Height = 500,
    [int]$Seconds = 90
)

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

Remove-Item $Log -ErrorAction SilentlyContinue
$writer = [System.IO.StreamWriter]::new($Log, $true)
$writer.AutoFlush = $true

function Write-Event([hashtable]$e) {
    $e['t'] = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
    $writer.WriteLine(($e | ConvertTo-Json -Compress))
}

$form = New-Object System.Windows.Forms.Form
$form.Text = 'web-client input target - safe to click'
$form.StartPosition = 'Manual'
$form.Location = New-Object System.Drawing.Point($X, $Y)
$form.Size = New-Object System.Drawing.Size($Width, $Height)
$form.TopMost = $true
$form.KeyPreview = $true
$form.BackColor = [System.Drawing.Color]::FromArgb(20, 22, 26)

# A TextBox, not a Label. A docked Label covers the form and swallows every mouse event
# without forwarding it, and cannot take keyboard focus at all - so a Label-based target
# records nothing and looks exactly like a broken client.
$box = New-Object System.Windows.Forms.TextBox
$box.Multiline = $true
$box.Dock = 'Fill'
$box.BackColor = [System.Drawing.Color]::FromArgb(20, 22, 26)
$box.ForeColor = [System.Drawing.Color]::FromArgb(230, 233, 239)
$box.Font = New-Object System.Drawing.Font('Consolas', 12)
$box.BorderStyle = 'None'
$box.ScrollBars = 'Vertical'
$box.Text = "input target - clicks and keys land here and do nothing`r`n" + ("filler line`r`n" * 40)
$form.Controls.Add($box)

$script:count = 0

# Screen coordinates are what the remote injects in, so every position is converted back
# out of client space for comparison with what was sent.
$toScreen = {
    param($e)
    $p = $form.PointToScreen((New-Object System.Drawing.Point($e.X, $e.Y)))
    @{ x = $p.X; y = $p.Y }
}

# Handlers go on the control that actually receives the events, not just the form.
foreach ($target in @($form, $box)) {
    $target.Add_MouseDown({
        $p = & $toScreen $_
        Write-Event @{ kind = 'mousedown'; button = "$($_.Button)"; x = $p.x; y = $p.y }
        $script:count++
    })
    $target.Add_MouseUp({
        $p = & $toScreen $_
        Write-Event @{ kind = 'mouseup'; button = "$($_.Button)"; x = $p.x; y = $p.y }
        $script:count++
    })
    $target.Add_MouseMove({
        $p = & $toScreen $_
        Write-Event @{ kind = 'mousemove'; x = $p.x; y = $p.y; buttons = "$($_.Button)" }
    })
    $target.Add_MouseWheel({
        Write-Event @{ kind = 'wheel'; delta = $_.Delta }
        $script:count++
    })
    $target.Add_KeyDown({
        Write-Event @{ kind = 'keydown'; key = "$($_.KeyCode)"; shift = $_.Shift; ctrl = $_.Control; alt = $_.Alt }
        $script:count++
    })
    $target.Add_KeyPress({
        Write-Event @{ kind = 'char'; ch = "$($_.KeyChar)" }
        $script:count++
    })
}

# Refresh the on-screen counter so a human watching can see events arriving live.
$ui = New-Object System.Windows.Forms.Timer
$ui.Interval = 250
$ui.Add_Tick({
    $form.Text = "web-client input target - events: $script:count"
})
$ui.Start()

$life = New-Object System.Windows.Forms.Timer
$life.Interval = [Math]::Max(1000, $Seconds * 1000)
$life.Add_Tick({ $form.Close() })
$life.Start()

$form.Add_Shown({
    # Windows suppresses focus stealing from a background process, so activation needs
    # more than Activate(): the TopMost flip forces the window to the front, and the
    # TextBox must hold focus or keystrokes go nowhere.
    $form.Activate()
    $form.BringToFront()
    $form.TopMost = $false
    $form.TopMost = $true
    [void]$box.Focus()
    $box.SelectionStart = 0
    $box.SelectionLength = 0
    $b = $form.Bounds
    $c = $form.RectangleToScreen($form.ClientRectangle)
    Write-Event @{ kind = 'ready'; bounds = @{ x = $b.X; y = $b.Y; w = $b.Width; h = $b.Height };
                   client = @{ x = $c.X; y = $c.Y; w = $c.Width; h = $c.Height };
                   hwnd = [int64]$form.Handle }
})

[void]$form.ShowDialog()
Write-Event @{ kind = 'closed'; total = $script:count }
$writer.Close()
