package acme

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
)

// fakeCloudflare bildet die wenigen Endpunkte nach, die der Setzer nutzt:
// Zonen suchen, TXT-Record anlegen, auflisten, löschen.
type fakeCloudflare struct {
	mu      sync.Mutex
	records map[string]cfRecord // id -> record
	next    int
	token   string
	gotAuth string
}

func newFakeCloudflare(token string) *fakeCloudflare {
	return &fakeCloudflare{records: make(map[string]cfRecord), token: token}
}

func (f *fakeCloudflare) writeEnv(w http.ResponseWriter, result any) {
	env := map[string]any{"success": true, "errors": []any{}, "result": result}
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(env)
}

func (f *fakeCloudflare) handler() http.Handler {
	mux := http.NewServeMux()

	mux.HandleFunc("/zones", func(w http.ResponseWriter, r *http.Request) {
		f.gotAuth = r.Header.Get("Authorization")
		var zones []cfZone
		if r.URL.Query().Get("name") == "example.test" {
			zones = []cfZone{{ID: "zone1", Name: "example.test"}}
		}
		f.writeEnv(w, zones)
	})

	mux.HandleFunc("/zones/", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		parts := strings.Split(strings.Trim(r.URL.Path, "/"), "/") // zones/zone1/dns_records[/recID]
		switch r.Method {
		case http.MethodPost:
			var body struct{ Name, Content string }
			_ = json.NewDecoder(r.Body).Decode(&body)
			f.next++
			id := "rec" + itoa(f.next)
			f.records[id] = cfRecord{ID: id, Name: body.Name, Content: body.Content}
			f.writeEnv(w, map[string]string{"id": id})
		case http.MethodGet:
			name := r.URL.Query().Get("name")
			var out []cfRecord
			for _, rec := range f.records {
				if rec.Name == name {
					out = append(out, rec)
				}
			}
			f.writeEnv(w, out)
		case http.MethodDelete:
			id := parts[len(parts)-1]
			delete(f.records, id)
			f.writeEnv(w, map[string]string{"id": id})
		}
	})
	return mux
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	var b []byte
	for n > 0 {
		b = append([]byte{byte('0' + n%10)}, b...)
		n /= 10
	}
	return string(b)
}

func TestCloudflareSetAndRemove(t *testing.T) {
	fake := newFakeCloudflare("geheim")
	srv := httptest.NewServer(fake.handler())
	defer srv.Close()

	setter := newCloudflareSetter("geheim")
	setter.baseURL = srv.URL

	ctx := context.Background()
	record := "_acme-challenge.panel.example.test"
	if err := setter.setTXT(ctx, "panel.example.test", record, "wert-1"); err != nil {
		t.Fatalf("setTXT: %v", err)
	}

	fake.mu.Lock()
	count := len(fake.records)
	var stored cfRecord
	for _, r := range fake.records {
		stored = r
	}
	fake.mu.Unlock()
	if count != 1 {
		t.Fatalf("%d Records angelegt, erwartet 1", count)
	}
	if stored.Name != record || stored.Content != "wert-1" {
		t.Errorf("Record = %+v", stored)
	}
	if fake.gotAuth != "Bearer geheim" {
		t.Errorf("Authorization = %q, erwartet Bearer geheim", fake.gotAuth)
	}

	if err := setter.removeTXT(ctx, "panel.example.test", record, "wert-1"); err != nil {
		t.Fatalf("removeTXT: %v", err)
	}
	fake.mu.Lock()
	remaining := len(fake.records)
	fake.mu.Unlock()
	if remaining != 0 {
		t.Errorf("%d Records nach dem Löschen, erwartet 0", remaining)
	}
}

func TestCloudflareZoneNotFound(t *testing.T) {
	fake := newFakeCloudflare("geheim")
	srv := httptest.NewServer(fake.handler())
	defer srv.Close()

	setter := newCloudflareSetter("geheim")
	setter.baseURL = srv.URL

	// example.org ist dem Fake unbekannt → keine Zone.
	if err := setter.setTXT(context.Background(), "panel.example.org", "_acme-challenge.panel.example.org", "x"); err == nil {
		t.Error("ohne passende Zone sollte setTXT scheitern")
	}
}
