import { Provider } from 'oidc-provider';
import express from 'express';
import cors from 'cors';

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
  // Always return a test account
  findAccount: async (ctx, id) => ({
    accountId: 'test123',
    async claims() {
      return {
        sub: 'test123',
        email: 'test@example.com',
        email_verified: true,
        name: 'Test User'
      };
    },
  }),
  // Auto-grant consent
  loadExistingGrant: async (ctx) => {
    const grant = new ctx.oidc.provider.Grant({
      clientId: ctx.oidc.client.clientId,
      accountId: 'test123',
    });
    grant.addOIDCScope('openid email profile');
    await grant.save();
    return grant;
  },
  // Skip interaction by immediately returning login result
  interactions: {
    async url(ctx, interaction) {
      return {
        result: {
          login: {
            accountId: 'test123'
          },
          consent: {
            grantId: await ctx.oidc.provider.Grant.new({ accountId: 'test123' })
          }
        },
        merge: true
      };
    }
  }
};

(async () => {
  const oidc = new Provider('http://localhost:25083', configuration);

  // Add debug logging
  oidc.on('authorization.error', (ctx, error) => {
    console.error('Authorization Error:', error);
  });

  oidc.on('grant.error', (ctx, error) => {
    console.error('Grant Error:', error);
  });

  app.use('/oidc', oidc.callback());
  
  app.listen(3000, () => {
    console.log('Auth service listening on port 3000');
  });
})();
