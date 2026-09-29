<?php

namespace App\Enums;

/**
 * Rayon d'un magasin.
 *
 * L'ordre des cas n'est pas décoratif : c'est celui dans lequel on traverse réellement un
 * supermarché belge, des fruits et légumes de l'entrée aux boissons près des caisses. C'est lui
 * qui sert à trier la liste de courses, pour qu'on ne revienne pas trois fois sur ses pas.
 */
enum Rayon: string
{
    case FruitsLegumes = 'fruits_legumes';
    case Boulangerie = 'boulangerie';
    case Boucherie = 'boucherie';
    case Poissonnerie = 'poissonnerie';
    case Cremerie = 'cremerie';
    case Feculents = 'feculents';
    case Epicerie = 'epicerie';
    case Surgeles = 'surgeles';
    case Boissons = 'boissons';
    case Autre = 'autre';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** Rang de traversée, à partir de 1. Un rayon inconnu passe en dernier. */
    public function ordre(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::FruitsLegumes->value => 'Fruits et légumes',
            self::Boulangerie->value => 'Pain et boulangerie',
            self::Boucherie->value => 'Boucherie et volaille',
            self::Poissonnerie->value => 'Poissonnerie',
            self::Cremerie->value => 'Crèmerie et œufs',
            self::Feculents->value => 'Pâtes, riz et féculents',
            self::Epicerie->value => 'Épicerie',
            self::Surgeles->value => 'Surgelés',
            self::Boissons->value => 'Boissons',
            self::Autre->value => 'Divers',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Rang de traversée d'une valeur brute ; ce qu'on ne reconnaît pas finit à la fin. */
    public static function ordreDe(?string $valeur): int
    {
        return self::tryFrom((string) $valeur)?->ordre() ?? count(self::cases()) + 1;
    }

    /** Catalogue ordonné, tel que l'écran doit le présenter. */
    public static function catalogue(): array
    {
        return array_map(
            fn (self $rayon) => ['cle' => $rayon->value, 'libelle' => $rayon->label(), 'ordre' => $rayon->ordre()],
            self::cases(),
        );
    }
}
