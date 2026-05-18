package client

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"time"

	"github.com/AlfiMuhtadii/enterprise-SIEM/simulators/xdr-scenario-runner/types"
)

type Client struct {
	baseURL string
	http    *http.Client
}

func NewClient(baseURL string) *Client {
	return &Client{
		baseURL: baseURL,
		http:    &http.Client{Timeout: 10 * time.Second},
	}
}

func (c *Client) Send(event types.TelemetryEvent) error {
	payload, err := json.Marshal(event)
	if err != nil {
		return err
	}

	resp, err := c.http.Post(c.baseURL, "application/json", bytes.NewBuffer(payload))
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusAccepted {
		return fmt.Errorf("ingestion failed with status: %d", resp.StatusCode)
	}
	return nil
}
