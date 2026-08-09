# vhs script for the README gif: https://github.com/charmbracelet/vhs
#
#   vhs demo.tape
#
# VHS sizes the window in pixels, not in cells. At FontSize 14 these values
# give about 100 columns and 17 rows, which is the table plus the status and
# hint lines with nothing left over: a 30-row window spent two thirds of the
# frame on empty space.

Require php

Output .github/demo.gif

Set Shell bash
Set FontSize 14
Set Width 950
Set Height 340
Set Padding 20
Set TypingSpeed 90ms

Type "php examples/demo.php"
Sleep 700ms
Enter

# Give the widget a moment to paint before anything moves.
Wait+Screen /Downloads/
Sleep 2s

# Walk down a few rows, one keypress at a time.
Down 5
Sleep 2s

# A whole page at once, so the scroll indicator jumps.
PageDown
Sleep 2s

# Move the column cursor onto Downloads.
Right
Sleep 1500ms

# First 's' sorts ascending, second flips it.
Type "s"
Sleep 2500ms
Type "s"
Sleep 2500ms

# Filter down to the symfony packages.
Type "f"
Sleep 2500ms

Type "q"
Sleep 1s
