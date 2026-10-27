<?php

namespace SpotEx\Pay;

/** A webhook whose signature, or age, does not check out. Answer it with 400. */
class SignatureVerificationException extends \RuntimeException
{
}
