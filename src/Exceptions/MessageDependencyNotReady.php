<?php

namespace Nexzan\Shared\Exceptions;

use RuntimeException;

/** The message is valid, but an earlier event has not created its local dependency yet. */
class MessageDependencyNotReady extends RuntimeException {}
