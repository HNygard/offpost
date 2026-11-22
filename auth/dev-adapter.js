// Development-only oidc-provider adapter.
//
// oidc-provider keeps everything in memory by default, so every restart of the auth service
// (including nodemon restarts on code edits) forgets dynamically registered clients. MCP clients
// such as Claude Code cache their client_id and then get `invalid_client`. This adapter persists
// the `Client` model (dynamic registrations) to auth/.oidc-store/clients.json (gitignored, and
// ignored by nodemon in package.json). Everything else - sessions, grants, codes, tokens - stays
// in memory, so after a restart the user simply logs in again.
import fs from 'fs';
import MemoryAdapter from 'oidc-provider/lib/adapters/memory_adapter.js';

const STORE_DIR = new URL('./.oidc-store/', import.meta.url);
const STORE_FILE = new URL('./.oidc-store/clients.json', import.meta.url);

function loadClients() {
  try {
    return JSON.parse(fs.readFileSync(STORE_FILE, 'utf8'));
  } catch {
    return {};
  }
}

function saveClients(clients) {
  fs.mkdirSync(STORE_DIR, { recursive: true });
  const tmp = new URL(`./.oidc-store/clients.json.${process.pid}.tmp`, import.meta.url);
  fs.writeFileSync(tmp, JSON.stringify(clients, null, 2), { mode: 0o600 });
  fs.renameSync(tmp, STORE_FILE);
}

export default class DevAdapter extends MemoryAdapter {
  async upsert(id, payload, expiresIn) {
    if (this.model === 'Client') {
      const clients = loadClients();
      clients[id] = payload;
      saveClients(clients);
      return;
    }
    return super.upsert(id, payload, expiresIn);
  }

  async find(id) {
    if (this.model === 'Client') {
      return loadClients()[id];
    }
    return super.find(id);
  }

  async destroy(id) {
    if (this.model === 'Client') {
      const clients = loadClients();
      delete clients[id];
      saveClients(clients);
      return;
    }
    return super.destroy(id);
  }
}
