package privops

import "context"

// System ist die echte Implementierung des Executors.
type System struct {
	runner Runner
}

// NewSystem baut einen Executor gegen das laufende System.
func NewSystem() *System {
	return &System{runner: ExecRunner{}}
}

// NewSystemWithRunner setzt einen eigenen Runner ein. Tests speisen darüber
// aufgezeichnete Kommandoausgaben ein, statt echte Systemaufrufe zu machen.
func NewSystemWithRunner(r Runner) *System {
	return &System{runner: r}
}

// Ein Compile-Time-Nachweis, dass System das Interface vollständig erfüllt.
var _ Executor = (*System)(nil)

func (s *System) run(ctx context.Context, cmd Command) (Result, error) {
	return s.runner.Run(ctx, cmd)
}
