// Only needed when USE_INSECURE_TLS is 0 in config.h (recommended for deployment).
// Copy to root_ca.h and paste the PEM of the certificate authority that signed your site's certificate.
// See docs/FIRMWARE.md ("Verifying the server's certificate") for how to get it.
#pragma once

static const char ROOT_CA[] PROGMEM = R"EOF(
-----BEGIN CERTIFICATE-----
PASTE THE ROOT (OR INTERMEDIATE) CA CERTIFICATE HERE
-----END CERTIFICATE-----
)EOF";
