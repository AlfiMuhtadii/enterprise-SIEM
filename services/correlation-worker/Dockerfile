FROM golang:1.26-alpine AS build
WORKDIR /src
COPY go.mod ./
COPY main.go ./
RUN go build -o /out/correlation-worker .

FROM alpine:3.22
RUN adduser -D -H app
USER app
COPY --from=build /out/correlation-worker /usr/local/bin/correlation-worker
EXPOSE 8093
ENTRYPOINT ["/usr/local/bin/correlation-worker"]
