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
  // Skip interaction by immediately returning login result
  async renderError(ctx, out, error) {
    logger.error('Render error', { error });
    ctx.type = 'json';
    ctx.body = { error: error.message };
  },
  // Auto-handle interaction without redirect
  async interactionResult(ctx) {
    const grant = new ctx.oidc.provider.Grant({
      accountId: 'test123',
      clientId: ctx.oidc.client.clientId,
    });
    grant.addOIDCScope('openid email profile');
    await grant.save();
    return {
      login: { accountId: 'test123' },
      consent: { grantId: grant.jti }
    };
  }
};

(async () => {
  const oidc = new Provider('http://localhost:25083', configuration);

  // Request logging
  app.use((req, res, next) => {
    logger.info('Request', { method: req.method, url: req.url });
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

  app.use('/oidc', oidc.callback());
  
  app.listen(3000, () => {
    logger.info('Auth service started', { port: 3000 });
  });
})();
