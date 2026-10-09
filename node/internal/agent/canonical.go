package agent

import (
	"bytes"
	"encoding/json"
	"fmt"
	"sort"
)

// canonicalJSON mirrors Laravel's CanonicalSnapshot: recursively sort object keys,
// preserve array order, emit compact UTF-8 JSON, and do not HTML-escape characters.
func canonicalJSON(raw json.RawMessage) ([]byte, error) {
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	var value any
	if err := dec.Decode(&value); err != nil {
		return nil, fmt.Errorf("decode snapshot: %w", err)
	}
	var out bytes.Buffer
	if err := writeCanonical(&out, value); err != nil {
		return nil, err
	}
	return out.Bytes(), nil
}

func writeCanonical(out *bytes.Buffer, value any) error {
	switch v := value.(type) {
	case map[string]any:
		keys := make([]string, 0, len(v))
		for k := range v {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		out.WriteByte('{')
		for i, k := range keys {
			if i > 0 { out.WriteByte(',') }
			keyBytes, _ := json.Marshal(k)
			out.Write(keyBytes)
			out.WriteByte(':')
			if err := writeCanonical(out, v[k]); err != nil { return err }
		}
		out.WriteByte('}')
	case []any:
		out.WriteByte('[')
		for i, item := range v {
			if i > 0 { out.WriteByte(',') }
			if err := writeCanonical(out, item); err != nil { return err }
		}
		out.WriteByte(']')
	case json.Number:
		out.WriteString(string(v))
	case string, bool, nil, float64:
		b, err := json.Marshal(v)
		if err != nil { return err }
		// Go's encoder HTML-escapes <, > and &, unlike Laravel's canonical encoder.
		b = bytes.ReplaceAll(b, []byte(`\u003c`), []byte("<"))
		b = bytes.ReplaceAll(b, []byte(`\u003e`), []byte(">"))
		b = bytes.ReplaceAll(b, []byte(`\u0026`), []byte("&"))
		out.Write(b)
	default:
		return fmt.Errorf("unsupported JSON value %T", value)
	}
	return nil
}
