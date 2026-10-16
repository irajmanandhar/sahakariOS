import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  /* config options here */
  cacheComponents: true,
  partialPrefetching: true,
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
