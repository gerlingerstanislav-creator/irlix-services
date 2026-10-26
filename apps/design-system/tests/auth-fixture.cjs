// Synthetic authenticated browser session: never contains real employee data.
async function configureAuthenticatedPreview(page, base) {
  const origin = new URL(base).origin;
  const issuer = origin + '/keycloak/auth/realms/irlix';
  const claims = {
    iss: issuer,
    sub: 'synthetic-employee-42',
    preferred_username: 'synthetic.employee',
    name: 'Демо сотрудник',
    exp: Math.floor(Date.now() / 1000) + 3600,
  };
  const access = {
    employee_id: 42,
    employee_name: 'Демо сотрудник',
    allowed: false,
    roles: ['employee'],
    services: {
      dashboard: true, employees: true, vacations: true,
      'design-system': true, clients: false, brandbook: false, system: false,
    },
  };
  const token = 'test.' + Buffer.from(JSON.stringify(claims)).toString('base64url') + '.test';
  await page.addInitScript(tokens => {
    sessionStorage.setItem('irlix.platform.auth.tokens', JSON.stringify(tokens));
  }, { access_token: token, refresh_token: 'test-refresh' });
  await page.route('**/keycloak/auth/realms/irlix/.well-known/openid-configuration', route =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      issuer,
      authorization_endpoint: issuer + '/protocol/openid-connect/auth',
      token_endpoint: issuer + '/protocol/openid-connect/token',
    }) }),
  );
  await page.route('**/api/employees/access/me', route =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: access }) }),
  );
}
module.exports = { configureAuthenticatedPreview };
