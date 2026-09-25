FROM node:22-alpine AS base
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci || npm install
COPY . .
RUN npm run build
EXPOSE 3000
ENV PORT=3000 HOSTNAME="0.0.0.0"
CMD ["npm", "start"]
