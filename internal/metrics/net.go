package metrics

import "net"

// netInterfaces liefert die IP-Adressen je Schnittstelle. Ausgelagert, damit
// proc.go ohne net-Import auskommt und der Testpfad klein bleibt.
func netInterfaces() (map[string][]string, error) {
	ifaces, err := net.Interfaces()
	if err != nil {
		return nil, err
	}

	out := make(map[string][]string, len(ifaces))
	for _, iface := range ifaces {
		if iface.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, err := iface.Addrs()
		if err != nil {
			continue
		}
		for _, a := range addrs {
			ipnet, ok := a.(*net.IPNet)
			if !ok || ipnet.IP.IsLinkLocalUnicast() {
				continue
			}
			out[iface.Name] = append(out[iface.Name], ipnet.String())
		}
	}
	return out, nil
}
