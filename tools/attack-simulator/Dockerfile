# Build stage
FROM golang:1.26-alpine@sha256:3ad57304ad93bbec8548a0437ad9e06a455660655d9af011d58b993f6f615648 AS builder
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
