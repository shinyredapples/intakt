<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because code of the change could alter the tools or inputs of a static gate while that gate judges the head, a gate that runs it starts only once every static gate has ended.
interface RunsTheChangesCode extends Gate
{
}
