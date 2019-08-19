/*
** Zabbix
** Copyright (C) 2001-2019 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

package zbxcomms

import (
	"bytes"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"strings"
	"time"
	"zabbix/pkg/log"
	"zabbix/pkg/tls"
)

const headerSize = 13

const (
	connStateAccept = iota + 1
	connStateConnect
	connStateEstabilished
)

type Connection struct {
	conn      net.Conn
	tlsConfig *tls.Config
	state     int
}

type Listener struct {
	listener  net.Listener
	tlsconfig *tls.Config
}

func Open(address string, timeout time.Duration, args ...interface{}) (c *Connection, err error) {
	c = &Connection{state: connStateConnect}
	if c.conn, err = net.DialTimeout("tcp", address, timeout); nil != err {
		return
	}

	if err = c.conn.SetReadDeadline(time.Now().Add(timeout)); nil != err {
		return
	}

	if ferr = c.conn.SetWriteDeadline(time.Now().Add(timeout)); nil != err {
		return
	}

	var tlsconfig *tls.Config
	if len(args) > 0 {
		var ok bool
		if tlsconfig, ok = args[0].(*tls.Config); !ok {
			return nil, fmt.Errorf("invalid TLS configuration parameter of type %T", args[0])
		}
		if c.conn, err = tls.NewClient(c.conn, tlsconfig, timeout); err != nil {
			return
		}
	}
	return
}

func write(w io.Writer, data []byte) error {
	var b bytes.Buffer

	b.Grow(len(data) + headerSize)
	b.Write([]byte{'Z', 'B', 'X', 'D', 0x01})
	err := binary.Write(&b, binary.LittleEndian, uint64(len(data)))
	if nil != err {
		return err
	}
	b.Write(data)
	_, err = w.Write(b.Bytes())

	return err
}

func (c *Connection) Write(data []byte, timeout time.Duration) error {
	if timeout != 0 {
		err := c.conn.SetWriteDeadline(time.Now().Add(timeout))
		if nil != err {
			return err
		}
	}
	return write(c.conn, data)
}

func (c *Connection) WriteString(s string, timeout time.Duration) error {
	return c.Write([]byte(s), timeout)
}

func read(r io.Reader, pending []byte) ([]byte, error) {
	const maxRecvDataSize = 128 * 1048576
	var total int
	var b [2048]byte

	s := b[:]
	if pending != nil {
		total = len(pending)
		if total > len(b) {
			return nil, errors.New("pending data exceeds limit of 2KB bytes")
		}
		copy(s, pending)
	}

	for total < headerSize {
		n, err := r.Read(s[total:])
		if err != nil && err != io.EOF {
			return nil, fmt.Errorf("Cannot read message: '%s'", err)
		}

		if n == 0 {
			break
		}

		total += n
	}

	if total < 13 {
		if total == 0 {
			return []byte{}, nil
		}
		return nil, fmt.Errorf("Message is missing header.")
	}

	if !bytes.Equal(s[:4], []byte{'Z', 'B', 'X', 'D'}) {
		return nil, fmt.Errorf("Message is using unsupported protocol.")
	}

	if s[4] != 0x01 {
		return nil, fmt.Errorf("Message is using unsupported protocol version.")
	}

	expectedSize := binary.LittleEndian.Uint32(s[5:9])

	if expectedSize > maxRecvDataSize {
		return nil, fmt.Errorf("Message size %d exceeds the maximum size %d bytes.", expectedSize, maxRecvDataSize)
	}

	if int(expectedSize) < total-headerSize {
		return nil, fmt.Errorf("Message is longer than expected.")
	}

	if int(expectedSize) == total-headerSize {
		return s[headerSize:total], nil
	}

	sTmp := make([]byte, expectedSize+1)
	if total > headerSize {
		copy(sTmp, s[headerSize:total])
	}
	s = sTmp
	total = total - headerSize

	for total < int(expectedSize) {
		n, err := r.Read(s[total:])
		if err != nil {
			return nil, err
		}

		if n == 0 {
			break
		}

		total += n
	}

	if total != int(expectedSize) {
		return nil, fmt.Errorf("Message size is shorted or longer than expected.")
	}

	return s[:total], nil
}

func (c *Connection) Read(timeout time.Duration) (data []byte, err error) {
	if timeout != 0 {
		if err = c.conn.SetReadDeadline(time.Now().Add(timeout)); err != nil {
			return
		}
	}
	if c.state == connStateAccept && c.tlsConfig != nil {
		c.state = connStateEstabilished

		b := make([]byte, 1)
		var n int
		if n, err = c.conn.Read(b); err != nil {
			return
		}
		if n == 0 {
			return nil, errors.New("connection closed")
		}
		if b[0] != '\x16' {
			// unencrypted connection
			if c.tlsConfig.Accept&tls.ConnUnencrypted == 0 {
				return nil, errors.New("cannot accept unencrypted connection")
			}
			return read(c.conn, b)
		}
		if c.tlsConfig.Accept&(tls.ConnPSK|tls.ConnCert) == 0 {
			return nil, errors.New("cannot accept encrypted connection")
		}
		var tlsConn net.Conn
		if tlsConn, err = tls.NewServer(c.conn, c.tlsConfig, b, timeout); err != nil {
			return
		}
		c.conn = tlsConn
	}

	return read(c.conn, nil)
}

func (c *Connection) RemoteIP() string {
	addr := c.conn.RemoteAddr().String()
	if pos := strings.Index(addr, ":"); pos != -1 {
		addr = addr[:pos]
	}
	return addr
}

func Listen(address string, args ...interface{}) (c *Listener, err error) {
	var tlsconfig *tls.Config
	if len(args) > 0 {
		var ok bool
		if tlsconfig, ok = args[0].(*tls.Config); !ok {
			return nil, fmt.Errorf("invalid TLS configuration parameter of type %T", args[0])
		}
	}
	l, tmperr := net.Listen("tcp", address)
	if tmperr != nil {
		return nil, fmt.Errorf("Listen failed: %s", tmperr.Error())
	}
	c = &Listener{listener: l.(*net.TCPListener), tlsconfig: tlsconfig}
	return
}

func (l *Listener) Accept() (c *Connection, err error) {
	var conn net.Conn
	if conn, err = l.listener.Accept(); err != nil {
		return
	} else {
		c = &Connection{conn: conn, tlsConfig: l.tlsconfig, state: connStateAccept}
	}
	return
}

func (c *Connection) Close() (err error) {
	if c.conn != nil {
		err = c.conn.Close()
	}
	return
}

func (c *Listener) Close() (err error) {
	return c.listener.Close()
}

func Exchange(address string, timeout time.Duration, data []byte, args ...interface{}) ([]byte, error) {
	log.Tracef("connecting to [%s]", address)

	var tlsconfig *tls.Config
	if len(args) > 0 {
		var ok bool
		if tlsconfig, ok = args[0].(*tls.Config); !ok {
			return nil, fmt.Errorf("invalid TLS configuration parameter of type %T", args[0])
		}
	}

	c, err := Open(address, time.Second*time.Duration(timeout), tlsconfig)
	if err != nil {
		log.Tracef("cannot connect to [%s]: %s", address, err)
		return nil, err
	}

	defer c.Close()

	log.Tracef("sending [%s] to [%s]", string(data), address)

	err = c.Write(data, 0)
	if err != nil {
		log.Tracef("cannot send to [%s]: %s", address, err)
		return nil, err
	}

	log.Tracef("receiving data from [%s]", address)

	b, err := c.Read(0)
	if err != nil {
		log.Tracef("cannot receive data from [%s]: %s", address, err)
		return nil, err
	}
	log.Tracef("received [%s] from [%s]", string(b), address)

	if len(b) == 0 {
		return nil, errors.New("connection closed")
	}

	return b, nil
}
