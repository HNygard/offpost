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

// Development auto-login token
const DEV_LOGIN_TOKEN = 'dev-token';

// Auto-created development user
const DEV_USER = {
  username: 'devuser',
  id: 'dev-user-id',
  authenticated: 'SUCCESS'
};

// User management functions
async function loadUsers() {
  try {
    const usersDir = '/data/users';
    const files = await fs.readdir(usersDir);
    const users = [];
    
    for (const file of files) {
      if (file.endsWith('.json')) {
        const data = await fs.readFile(path.join(usersDir, file), 'utf8');
        users.push(JSON.parse(data));
      }
    }
    return users;
  } catch (error) {
    logger.error('Error loading users:', error);
    return [];
  }
}

async function saveUser(user) {
  try {
    const usersDir = '/data/users';
    await fs.mkdir(usersDir, { recursive: true });
    
    // Use username as part of the file path
    const filePath = path.join(usersDir, `${user.username}.json`);
    await fs.writeFile(filePath, JSON.stringify(user, null, 2));
  } catch (error) {
    logger.error('Error saving user:', error);
    throw error;
  }
}

async function findUser(username) {
  try {
    const usersDir = '/data/users';
    const filePath = path.join(usersDir, `${username}.json`);
    const data = await fs.readFile(filePath, 'utf8');
    return JSON.parse(data);
  } catch (error) {
    return null;
  }
}

async function createUser(username) {
  const newUser = {
    username,
    id: `user-${Date.now()}`,
    authenticated: "SUCCESS" // Auto-authenticate for development
  };
  await saveUser(newUser);
  return newUser;
}

// Username validation function
function isValidUsername(username) {
  return /^[a-zA-Z]+$/.test(username);
}

// Auto-login endpoint for development
app.get('/dev-login', (req, res) => {
  res.json({ success: true, user: DEV_USER, loginToken: DEV_LOGIN_TOKEN });
});

const configuration = {
  clients: [{
    client_id: 'organizer',
    client_secret: 'secret',
    grant_types: ['authorization_code'],
    redirect_uris: ['http://localhost:25081/callback'],
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
    
    // In development, always return the dev user
    if (id === DEV_USER.id) {
      return {
        accountId: DEV_USER.id,
        async claims() {
          return {
            sub: DEV_USER.id,
            name: DEV_USER.username
          };
        },
      };
    }
    
    // For non-dev users, check the users directory
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
  // Save dev user on startup
  await saveUser(DEV_USER);
  
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

  // Mount OIDC provider routes first
  app.use('/oidc', oidc.callback());

  // Custom auth middleware to handle auto-login
  app.use('/oidc/auth', async (req, res, next) => {
    const { token } = req.query;

    // No token or invalid token - redirect to login page
    if (!token || token !== DEV_LOGIN_TOKEN) {
      const loginUrl = '/login.html?' + new URLSearchParams(req.query).toString();
      return res.redirect(loginUrl);
    }

    // Valid token - let OIDC provider handle the request
    next();
  });

  // Interaction endpoint
  app.get('/interaction/:uid', async (req, res) => {
    try {
      const details = await oidc.interactionDetails(req, res);
      const { uid, prompt, params } = details;
      
      logger.info('Processing interaction', { uid, prompt, params });

      // Get login token from query params
      const loginToken = req.query.token;
      
          // In development, always use the dev user
      const user = DEV_USER;

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
