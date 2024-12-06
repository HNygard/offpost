import { Provider } from 'oidc-provider';
import express from 'express';
import cors from 'cors';
import winston from 'winston';

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
    email: ['email', 'email_verified'],
    profile: ['name']
  },
  findAccount: async (ctx, id) => {
    logger.debug('Finding account', { id });
    return {
      accountId: 'test123',
      async claims() {
        return {
          sub: 'test123',
          email: 'test@example.com',
          email_verified: true,
          name: 'Test User'
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

  // Mount OIDC provider routes first
  app.use('/oidc', oidc.callback());

  // Then add the interaction endpoint
  app.use('/interaction/:uid', async (req, res, next) => {
    try {
      const {
        uid, prompt, params, session,
      } = await oidc.interactionDetails(req, res);
      
      logger.info('Processing interaction', { uid, prompt, params });

      const client = await oidc.Client.find(params.client_id);
      
      switch (prompt.name) {
        case 'login': {
          const result = {
            login: {
              accountId: 'test123',
              remember: true,
            },
          };
          
          await oidc.interactionFinished(req, res, result, { mergeWithLastSubmission: false });
          break;
        }
        case 'consent': {
          const grant = new oidc.Grant({
            accountId: 'test123',
            clientId: client.clientId,
          });
          
          grant.addOIDCScope('openid email profile');
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
          next(new Error('Unsupported prompt'));
      }
    } catch (err) {
      logger.error('Interaction error', { error: err.message, stack: err.stack });
      next(err);
    }
  });
  
  app.listen(3000, () => {
    logger.info('Auth service started', { 
      port: 3000,
      env: process.env.NODE_ENV || 'development'
    });
  });
})();
