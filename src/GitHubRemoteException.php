<?php
declare(strict_types=1);
namespace CajeerBook;

/** Safe diagnostic for a failed GitHub HTTPS operation; never includes response bodies or credentials. */
final class GitHubRemoteException extends \DomainException {}
