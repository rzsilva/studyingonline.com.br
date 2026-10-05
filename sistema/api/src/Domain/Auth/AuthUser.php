<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/** Identidade do usuário autenticado, extraída do JWT validado. */
final class AuthUser
{
    public function __construct(
        public readonly int $id,
        public readonly int $instituicaoId,
        public readonly int $perfilId,
        public readonly bool $master,
    ) {
    }

    public function perfil(): ?Perfil
    {
        return Perfil::fromId($this->perfilId);
    }

    public function is(Perfil ...$perfis): bool
    {
        return in_array($this->perfil(), $perfis, true);
    }

    public function isAdmin(): bool
    {
        return $this->perfilId === Perfil::Administrador->value;
    }

    public function isAluno(): bool
    {
        return $this->perfilId === Perfil::Aluno->value;
    }

    /** Admin/professor veem dados de terceiros da instituição; aluno só os próprios. */
    public function canAccessUser(int $usuarioId): bool
    {
        return !$this->isAluno() || $this->id === $usuarioId;
    }
}
