import type { NextConfig } from "next";

const dev = process.env.NODE_ENV !== "production";

// ponytail: 'unsafe-inline' scripts (Next's inline bootstrap) and styles (Emotion); move to a nonce-based
// CSP set in proxy.ts once pages are rendered per request (see docs/frontend.md, production checklist).
const csp = [
  "default-src 'self'",
  `script-src 'self' 'unsafe-inline'${dev ? " 'unsafe-eval'" : ""}`,
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data: blob:",
  "font-src 'self' data:",
  `connect-src 'self'${dev ? " ws: wss:" : ""}`,
  "frame-src 'self' blob:",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join("; ");

const securityHeaders = [
  { key: "Content-Security-Policy", value: csp },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "Referrer-Policy", value: "same-origin" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=(), payment=(), usb=()" },
  { key: "Cross-Origin-Opener-Policy", value: "same-origin" },
  // Ignored by browsers over plain HTTP; takes effect once production is served over HTTPS.
  ...(dev ? [] : [{ key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" }]),
];

const nextConfig: NextConfig = {
  /* config options here */
  cacheComponents: true,
  partialPrefetching: true,
  experimental: {
    // Staff pages render after the browser loads the session (cookie auth through the BFF), so they
    // cannot be instant; validate instant navigation only where a segment opts in with `instant`.
    instantInsights: { validationLevel: 'manual-warning' },
    // Settings → Import sends CSV files of up to 50 MB and waits for the import to finish.
    proxyClientMaxBodySize: "55mb",
    proxyTimeout: 900_000,
  },
  poweredByHeader: false,
  async headers() {
    return [{ source: "/:path*", headers: securityHeaders }];
  },
  // Backend-for-Frontend: the browser only ever talks to this origin. /api/*
  // is proxied server-side to Laravel, so Laravel's HttpOnly session cookie
  // and XSRF-TOKEN cookie are same-origin and no CORS is needed.
  // API_INTERNAL_URL is server-only and read when the server starts (dev) or
  // at build time (production build), never sent to the browser.
  async rewrites() {
    return [
      {
        source: "/api/:path*",
        destination: `${process.env.API_INTERNAL_URL ?? "http://localhost:8080"}/api/:path*`,
      },
    ];
  },
};

export default nextConfig;
