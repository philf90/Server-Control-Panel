package store

import (
	"io/fs"
	"os"
)

func statFile(path string) (fs.FileInfo, error) {
	return os.Stat(path)
}
