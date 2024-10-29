<?php

namespace App\DataFixtures;

use App\Entity\Etiquette;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Génération de plusieurs étiquettes avec des données de test
        for ($i = 1; $i <= 10; $i++) {
            $etiquette = new Etiquette();
            $etiquette->setNom("Produit $i");
            $etiquette->setDate(new \DateTime(sprintf('2024-11-%02d', $i))); // Date de test pour novembre 2024
            $etiquette->setProduit("Produit Type $i");
            $etiquette->setQuantity((string)rand(1, 100)); // Quantité aléatoire
            $etiquette->setCodeBarre(sprintf('CODE%04d', $i)); // Code-barres unique formaté

            $manager->persist($etiquette);
        }

        $manager->flush();
    }
}
