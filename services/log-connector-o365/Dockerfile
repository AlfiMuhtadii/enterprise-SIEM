FROM golang:1.26-alpine@sha256:3ad57304ad93bbec8548a0437ad9e06a455660655d9af011d58b993f6f615648 AS build
WORKDIR /src
COPY go.mod ./
COPY main.go ./
COPY internal ./internal
RUN go build -o /out/log-connector-o365 .

FROM alpine:3.22@sha256:14358309a308569c32bdc37e2e0e9694be33a9d99e68afb0f5ff33cc1f695dce
RUN adduser -D -H app
RUN mkdir -p /data/o365-state && chown app:app /data/o365-state
USER app
ENV XDR_O365_STATE_DIR=/data/o365-state
COPY --from=build /out/log-connector-o365 /usr/local/bin/log-connector-o365
EXPOSE 8100
ENTRYPOINT ["/usr/local/bin/log-connector-o365"]
