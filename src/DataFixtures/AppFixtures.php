<?php

namespace App\DataFixtures;

use App\Entity\Etiquette;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Création de 10 étiquettes fictives
        for ($i = 1; $i <= 10; $i++) {
            $etiquette = new Etiquette();
            $etiquette->setNom("Produit $i");
            $etiquette->setDate(new \DateTime("2024-10-$i"));
            $etiquette->setProduit("Produit $i");
            $etiquette->setQuantity((string) rand(1, 100));
            $etiquette->setCodeBarre("CODE$i");
            $etiquette->setTemplateName("template_$i");
            $etiquette->setPatterns([
                'nom',
                'date',
                'produit',
                'quantite',
                'code_barre',
            ]);

            $manager->persist($etiquette);
        }

        $manager->flush();
    }
}
