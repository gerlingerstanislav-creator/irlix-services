/**
 * A false result from auth.init() means OIDC is navigating to the login page.
 * Keep the initial loader visible until navigation replaces this document.
 * Only resolve the UI after authentication and the service access check.
 */
export async function runAuthenticatedStartup({ authenticate, initialize, onError, onReady }) {
  try {
    if (!await authenticate()) return;
    await initialize();
  } catch (error) {
    onError(error);
  }
  onReady();
}
