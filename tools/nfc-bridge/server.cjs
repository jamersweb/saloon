const express = require('express');
const cors = require('cors');
const { NFC } = require('nfc-pcsc');

const PORT = Number(process.env.NFC_BRIDGE_PORT || 35791);
const HOST = process.env.NFC_BRIDGE_HOST || '127.0.0.1';
const MAX_AGE_SECONDS = Number(process.env.NFC_UID_MAX_AGE_SECONDS || 600);

const app = express();
app.use(cors());
app.use(express.json());

let latest = null;
const readers = new Map();

function normalizeUid(uid) {
  return String(uid || '')
    .trim()
    .replace(/\s+/g, '')
    .toUpperCase();
}

function hasFreshUid() {
  if (!latest) return false;
  const ageMs = Date.now() - latest.timestamp;
  return ageMs <= MAX_AGE_SECONDS * 1000;
}

app.get('/health', (req, res) => {
  res.json({
    ok: true,
    readers: Array.from(readers.keys()),
    readerCount: readers.size,
    hasUid: Boolean(latest),
    hasFreshUid: hasFreshUid(),
    latest: latest
      ? {
          uid: latest.uid,
          reader: latest.reader,
          timestamp: latest.timestamp,
          isoTime: new Date(latest.timestamp).toISOString(),
        }
      : null,
  });
});

app.get('/uid', (req, res) => {
  if (!latest || !hasFreshUid()) {
    return res.status(404).json({
      error: 'No recent NFC UID available. Tap a card on the reader and try again.',
    });
  }

  const payload = {
    uid: latest.uid,
    reader: latest.reader,
    timestamp: latest.timestamp,
    isoTime: new Date(latest.timestamp).toISOString(),
  };

  if (String(req.query.consume || '').toLowerCase() === '1' || String(req.query.consume || '').toLowerCase() === 'true') {
    latest = null;
  }

  return res.json(payload);
});

app.post('/simulate', (req, res) => {
  if (process.env.NFC_BRIDGE_ENABLE_SIMULATE !== '1') {
    return res.status(403).json({ error: 'Simulation disabled.' });
  }

  const uid = normalizeUid(req.body?.uid);
  if (!uid) {
    return res.status(422).json({ error: 'uid is required.' });
  }

  latest = {
    uid,
    reader: 'SIMULATED',
    timestamp: Date.now(),
  };

  return res.json({ ok: true, latest });
});

app.listen(PORT, HOST, () => {
  console.log(`[nfc-bridge] Listening on http://${HOST}:${PORT}`);
});

const nfc = new NFC();

nfc.on('reader', (reader) => {
  readers.set(reader.name, true);
  console.log(`[nfc-bridge] Reader connected: ${reader.name}`);

  reader.on('card', (card) => {
    const uid = normalizeUid(card?.uid);
    if (!uid) return;

    latest = {
      uid,
      reader: reader.name,
      timestamp: Date.now(),
    };

    console.log(`[nfc-bridge] Card detected on ${reader.name}: ${uid}`);
  });

  reader.on('card.off', () => {
    // Kept intentionally empty: we keep the last UID for retrieval.
  });

  reader.on('error', (error) => {
    console.error(`[nfc-bridge] Reader error (${reader.name}):`, error?.message || error);
  });

  reader.on('end', () => {
    readers.delete(reader.name);
    console.log(`[nfc-bridge] Reader disconnected: ${reader.name}`);
  });
});

nfc.on('error', (error) => {
  console.error('[nfc-bridge] NFC service error:', error?.message || error);
});