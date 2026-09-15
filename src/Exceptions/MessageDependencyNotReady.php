<?php

namespace Nexzan\Shared\Exceptions;

use RuntimeException;

/** A valid operation is waiting for a dependency. Never use after an uncertain external effect. */
class MessageDependencyNotReady extends RuntimeException {}
