# Frontend image — build → standalone runtime.
#
# `next.config.ts` sets `output: "standalone"`, so `next build` emits a
# self-contained server plus the exact subset of node_modules it needs. The
# runtime stage therefore does NOT need `npm ci` nor the full node_modules tree:
# it copies the traced output and runs `node server.js`.

FROM node:22-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci || npm install

FROM deps AS build
WORKDIR /app
COPY . .
# `NEXT_PUBLIC_*` is inlined into the client bundle at build time, so it has to be
# known here rather than at run time. It defaults to the port the backend compose
# publishes; override it if you publish the API somewhere else.
ARG NEXT_PUBLIC_API_URL=http://localhost:8080/api
ARG NEXT_PUBLIC_SITE_ID=
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL
ENV NEXT_PUBLIC_SITE_ID=$NEXT_PUBLIC_SITE_ID
RUN npm run build

FROM node:22-alpine AS runtime
WORKDIR /app
ENV NODE_ENV=production
ENV PORT=3000
# Bind to every interface: a container that only listens on 127.0.0.1 is
# unreachable from outside, which looks exactly like a crashed server.
ENV HOSTNAME=0.0.0.0

# Standalone output only. The second/third COPY bring in what Next deliberately
# leaves out of `standalone` (served statically by the server itself): the
# build's static chunks and the public directory — which is also where the
# Vazirmatn font files live, so omitting it would break the whole RTL UI.
COPY --from=build /app/.next/standalone ./
COPY --from=build /app/.next/static ./.next/static
COPY --from=build /app/public ./public

EXPOSE 3000
CMD ["node", "server.js"]
