# Build stage
FROM golang:1.22-alpine AS builder
WORKDIR /app
COPY go.mod ./
RUN go mod download github.com/twmb/franz-go@v1.16.1 gopkg.in/yaml.v3@v3.0.1
COPY . .
RUN go mod tidy
RUN CGO_ENABLED=0 GOOS=linux go build -o simulator main.go

# Runtime stage
FROM alpine:latest
RUN apk --no-cache add ca-certificates
WORKDIR /app
COPY --from=builder /app/simulator .
COPY scenarios/ ./scenarios/
RUN mkdir -p /app/logs
CMD ["./simulator"]
