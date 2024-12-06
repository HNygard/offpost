import { Provider } from 'oidc-provider';
import express from 'express';
import cors from 'cors';
import winston from 'winston';
import fs from 'fs/promises';
import path from 'path';

// Configure Winston logger
const logger = winston.createLogger({
  level: 'debug',
  format: winston.format.combine(
    winston.format.timestamp(),
    winston.format.printf(({ level, message, timestamp, ...meta }) => {
      return `${timestamp} ${level}: ${message} ${JSON.stringify(meta)}`;
    })
  ),
  transports: [
    new winston.transports.Console()
  ]
});

const app = express();
app.use(cors());
app.use(express.json());
app.use(express.static('public'));

// Simple in-memory store for active logins
const activeLogins = new Map();

// User management functions
async function loadUsers() {
  try {
    const data = await fs.readFile('users.json', 'utf8');
    return JSON.parse(data).users;
  } catch (error) {
    logger.error('Error loading users:', error);
    return [];
  }
}

async function saveUsers(users) {
  try {
    await fs.writeFile('users.json', JSON.stringify({ users }, null, 2));
  } catch (error) {
    logger.error('Error saving users:', error);
  }
}

async function findUser(username) {
  const users = await loadUsers();
  return users.find(user => user.username === username);
}

async function createUser(username) {
  const users = await loadUsers();
  const newUser = {
    username,
    id: `user-${Date.now()}`,
    authenticated: "PENDING"
  };
  users.push(newUser);
  await saveUsers(users);
  return newUser;
}

// Login endpoint
app.post('/login', async (req, res) => {
  try {
    const { username } = req.body;
    if (!username) {
      return res.status(400).json({ error: 'Username is required' });
    }

    let user = await findUser(username);
    if (!user) {
      user = await createUser(username);
      logger.info('Created new user', { username });
    }

    // Generate login token and store user
    const loginToken = Math.random().toString(36).substring(2);
    activeLogins.set(loginToken, user);

    res.json({ success: true, user, loginToken });
  } catch (error) {
    logger.error('Login error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

const configuration = {
  clients: [{
    client_id: 'organizer',
    client_secret: 'secret',
    grant_types: ['authorization_code'],
    redirect_uris: ['http://localhost:25081/callback.php'],
    response_types: ['code'],
  }],
  pkce: {
    required: () => false,
  },
  features: {
    devInteractions: { enabled: false },
    registration: { enabled: false },
  },
  claims: {
    openid: ['sub'],
    profile: ['name']
  },
  findAccount: async (ctx, id) => {
    logger.debug('Finding account', { id });
    const users = await loadUsers();
    const user = users.find(u => u.id === id);
    
    if (!user || user.authenticated !== "SUCCESS") {
      throw new Error('User not found or not authenticated');
    }

    return {
      accountId: user.id,
      async claims() {
        return {
          sub: user.id,
          name: user.username
        };
      },
    };
  },
  async renderError(ctx, out, error) {
    logger.error('Render error', { error });
    ctx.type = 'json';
    ctx.body = { error: error.message };
  },
  interactions: {
    url(ctx, interaction) {
      return `/interaction/${interaction.uid}`;
    }
  }
};

(async () => {
  const oidc = new Provider('http://localhost:25083', configuration);

  // Request logging
  app.use((req, res, next) => {
    logger.info('Incoming request', { 
      method: req.method, 
      url: req.url,
      params: req.query 
    });
    next();
  });

  // Error logging
  oidc.on('server_error', (ctx, err) => {
    logger.error('Server error', { error: err.message, stack: err.stack });
  });

  oidc.on('grant.error', (ctx, err) => {
    logger.error('Grant error', { error: err.message, stack: err.stack });
  });

  oidc.on('interaction.error', (ctx, err) => {
    logger.error('Interaction error', { error: err.message, stack: err.stack });
  });

  // Success logging
  oidc.on('authorization.success', (ctx) => {
    logger.info('Authorization success', { client: ctx.oidc.client.clientId });
  });

  oidc.on('grant.success', (ctx) => {
    logger.info('Grant success', { client: ctx.oidc.client.clientId });
  });

  // Mount OIDC provider routes
  app.use('/oidc', oidc.callback());

  // Interaction endpoint
  app.use('/interaction/:uid', async (req, res) => {
    try {
      const {
        uid, prompt, params
      } = await oidc.interactionDetails(req, res);
      
      logger.info('Processing interaction', { uid, prompt, params });

      // Get login token from query params
      const loginToken = req.query.token;
      const user = loginToken ? activeLogins.get(loginToken) : null;

      if (!user) {
        return res.redirect('/login.html');
      }

      // Check if user is properly authenticated
      if (user.authenticated !== "SUCCESS") {
        return res.status(401).json({ error: 'User not approved yet' });
      }

      switch (prompt.name) {
        case 'login': {
          const result = {
            login: {
              accountId: user.id,
              remember: true,
            },
          };
          
          await oidc.interactionFinished(req, res, result, { mergeWithLastSubmission: false });
          break;
        }
        case 'consent': {
          const grant = new oidc.Grant({
            accountId: user.id,
            clientId: params.client_id,
          });
          
          grant.addOIDCScope('openid profile');
          await grant.save();
          
          const result = {
            consent: {
              grantId: grant.jti,
            },
          };
          
          await oidc.interactionFinished(req, res, result, { mergeWithLastSubmission: true });
          break;
        }
        default:
          throw new Error('Unsupported prompt');
      }
    } catch (err) {
      logger.error('Interaction error', { error: err.message, stack: err.stack });
      res.status(500).json({ error: err.message });
    }
  });
  
  app.listen(3000, () => {
    logger.info('Auth service started', { 
      port: 3000,
      env: process.env.NODE_ENV || 'development'
    });
  });
})();
