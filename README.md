# SPUD

SPUD is a daemon written in PHP that is executed by requesting the file via HTTP, once run the daemon can be accessed by a user by connecting to the IP address of the server on the port the daemon is running on in a program such as netcat.

SPUD provides the client with a small shell that can be used to invoke system commands and, in the future, hopefully a non-canonical text editor and other such things.

What makes SPUD special is that when it's running it's actually being interpreted by the PHP daemon already running on the server meaning when SPUD is run no extra processes are created and it cannot be seen in programs such as top or htop.