# NFC Reader Setup (ACR122U and compatible PC/SC readers)

## Compatibility Summary
- Your Saloon system supports NFC by saving and looking up `nfc_uid` values.
- It does not read USB readers directly by itself.
- This bridge adds direct reader support by exposing the latest scanned UID to the Loyalty page.

## Prerequisites (Windows)
- Node.js `20.x` recommended for this bridge.
- ACS ACR122U PC/SC driver installed.
- Visual Studio Build Tools with `Desktop development with C++` (required by `node-gyp` for `nfc-pcsc`).

If your default Node is another version, switch before install:

```bash
nvm use 20.19.4
```

## 1. Install bridge dependencies
Run from project root:

```bash
npm run nfc:bridge:install
```

## 2. Start the NFC bridge

```bash
npm run nfc:bridge
```

Expected log:

```text
[nfc-bridge] Listening on http://127.0.0.1:35791
[nfc-bridge] Reader connected: ...
```

## 3. Use in Loyalty page
Open Loyalty module and use `Read UID` in these sections:
- Assign Membership Card
- NFC Scan Lookup (membership)
- Bind / Replace NFC UID (membership)
- Issue Gift Card (optional NFC UID for physical gift media)
- Gift Card NFC Scan Lookup
- Bind / Replace Gift Card NFC UID

The same NFC UID cannot be linked to both a membership card and a gift card at once.

When a card is tapped on the reader, the UID is auto-filled.

## Notes for ACR122U
- ACR122U works through PC/SC drivers and a local reader service.
- Because ACR122U is end-of-life, buy from trusted seller and verify genuine hardware.
- If the browser cannot read UID, confirm bridge is running and the reader appears in bridge logs.
- If `npm run nfc:bridge:install` fails with `node-gyp` Visual Studio errors, install Visual Studio Build Tools C++ workload and retry.

## Quick health checks
Bridge health:

```text
http://127.0.0.1:35791/health
```

Get latest UID once:

```text
http://127.0.0.1:35791/uid?consume=1
```
