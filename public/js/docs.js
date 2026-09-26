// Initialises the self-hosted Scalar bundle for /docs. Kept out of the HTML so
// the page's Content-Security-Policy needs no 'unsafe-inline' for scripts.
// Everything that would reach another origin (hosted fonts, telemetry, the
// hosted agent and its API registry) is switched off: the page talks only to
// this API.
(function () {
  var app = document.getElementById('app');

  Scalar.createApiReference('#app', {
    url: app.getAttribute('data-vd-openapi-url'),
    withDefaultFonts: false,
    telemetry: false,
    agent: { disabled: true },
    showDeveloperTools: 'never',
    hideDownloadButton: false,
    authentication: {
      preferredSecurityScheme: 'bearerAuth',
      securitySchemes: { bearerAuth: { token: app.getAttribute('data-vd-try-it-key') || '' } },
    },
    metaData: { title: 'vehicle-data-api - API reference' },
  });
})();
