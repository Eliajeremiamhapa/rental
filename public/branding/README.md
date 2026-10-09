# Receipt branding assets

Drop the UVIMO receipt assets here:

- `logo.png` — company logo shown at the top of every receipt.
- `signature.png` — electronic signature shown in the signature block.

Both are embedded into downloaded receipts automatically. To use different
filenames or locations, set `RECEIPT_LOGO_PATH` and `RECEIPT_SIGNATURE_PATH`
in the environment (absolute path, path inside `public/`, path inside
`storage/app/`, or a full URL).

## Requirements

- `signature.png` must be **landscape** (wider than tall). A portrait scan gets
  squeezed to roughly a 35 x 60 px sliver in the signature box.
- A transparent background is preferred; a scan with white paper behind it
  renders as a visible rectangular block.
- Keep the ink filling most of the canvas. Large empty margins shrink it.

`source/` holds unprocessed uploads and alternates. Nothing in that folder is
served or embedded — only the two files above are read by the application.

## Regenerating the signature

If a better scan arrives, rotate it upright, trim the paper, and convert the
white background to transparency rather than re-exporting by hand. Verified
output for the current upload is 569 x 331 with alpha, rendering 103 x 60 px.
