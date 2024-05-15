<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserService {
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher) {
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
    }

    public function createUser(string $email, string $nom, string $prenom, string $matricule): User {
        $user = new User();
        $user->setEmail($email);
        $user->setNom($nom);
        $user->setPrenom($prenom);
        $user->setMatricule($matricule);

        // Générer un mot de passe aléatoire
        $plainPassword = bin2hex(random_bytes(10)); // Génère un mot de passe aléatoire de 20 caractères hexadécimaux
        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function importUsersFromCsv(string $csvFilePath) {
        if (($handle = fopen($csvFilePath, 'r')) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $email = $data[0];
                $nom = $data[1];
                $prenom = $data[2];
                $matricule = $data[3];
                $this->createUser($email, $nom, $prenom, $matricule);
            }
            fclose($handle);
        }
    }

    public function updateUser(User $user, string $newEmail = null, string $newNom = null, string $newPrenom = null, string $newMatricule = null, string $newPlainPassword = null): User {
        if ($newEmail !== null) {
            $user->setEmail($newEmail);
        }
        if ($newNom !== null) {
            $user->setNom($newNom);
        }
        if ($newPrenom !== null) {
            $user->setPrenom($newPrenom);
        }
        if ($newMatricule !== null) {
            $user->setMatricule($newMatricule);
        }
        if ($newPlainPassword !== null) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $newPlainPassword);
            $user->setPassword($hashedPassword);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
