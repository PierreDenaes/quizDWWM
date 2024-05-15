<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\UserService;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud, KeyValueStore};
use EasyCorp\Bundle\EasyAdminBundle\Field\{BooleanField, IdField, EmailField, TextField};
use Symfony\Component\Form\Extension\Core\Type\{PasswordType, RepeatedType};
use Symfony\Component\Routing\Annotation\Route;

class UserCrudController extends AbstractCrudController
{
    private UserService $userService;
    private AdminUrlGenerator $adminUrlGenerator;
    private UserPasswordHasherInterface $userPasswordHasher;

    public function __construct(UserService $userService, AdminUrlGenerator $adminUrlGenerator, UserPasswordHasherInterface $userPasswordHasher)
    {
        $this->userService = $userService;
        $this->adminUrlGenerator = $adminUrlGenerator;
        $this->userPasswordHasher = $userPasswordHasher;
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle(Crud::PAGE_INDEX, 'Users')
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural('Users')
            ->setFormOptions(['validation_groups' => ['Default']])
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(10)
            ->overrideTemplates([
                'crud/index' => 'admin/user_crud/index.html.twig', // Override the index template
            ]);
    }

    public function configureActions(Actions $actions): Actions
    {
        $importUsersAction = Action::new('importUsers', 'Import Users')
            ->setCssClass('btn btn-primary')
            ->linkToCrudAction('importUsers');

        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, function (Action $action) {
                return $action->setCssClass('btn btn-success');
            })
            ->update(Crud::PAGE_INDEX, Action::DELETE, function (Action $action) {
                return $action->setCssClass('btn btn-danger');
            })
            ->add(Crud::PAGE_INDEX, $importUsersAction)
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        $roles = ['ROLE_ADMIN', 'ROLE_USER'];
        $fields = [
            IdField::new('id')->hideOnForm(),
            TextField::new('nom'),
            TextField::new('prenom'),
            TextField::new('matricule'),
            EmailField::new('email'),
            ChoiceField::new('roles')
                ->setChoices(array_combine($roles, $roles))
                ->allowMultipleChoices()
                ->renderExpanded()
                ->renderAsBadges(),
        ];
        $password = TextField::new('password')
            ->setFormType(RepeatedType::class)
            ->setFormTypeOptions([
                'type' => PasswordType::class,
                'first_options' => ['label' => 'Mot de passe'],
                'second_options' => ['label' => '(Confirmer mot de passe)'],
                'mapped' => false,
            ])
            ->setRequired($pageName === Crud::PAGE_NEW)
            ->onlyOnForms();
        $fields[] = $password;
        $isActive = BooleanField::new('isActive');

        $fields[] = $isActive;

        return $fields;
    }

    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $formBuilder = parent::createNewFormBuilder($entityDto, $formOptions, $context);
        return $this->addPasswordEventListener($formBuilder);
    }

    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $formBuilder = parent::createEditFormBuilder($entityDto, $formOptions, $context);
        $user = $context->getEntity()->getInstance();
        $user->setPlainPassword($user->getPassword()); // Sauvegardez le mot de passe original
        return $this->addPasswordEventListener($formBuilder);
    }

    private function addPasswordEventListener(FormBuilderInterface $formBuilder): FormBuilderInterface
    {
        return $formBuilder->addEventListener(FormEvents::POST_SUBMIT, $this->hashPassword());
    }

    private function hashPassword()
    {
        return function($event) {
            $form = $event->getForm();
            if (!$form->isValid()) {
                return;
            }
            $password = $form->get('password')->getData();
            if ($password === null || $password === $form->getData()->getPlainPassword()) {
                return; // Ne pas hasher si le mot de passe n'est pas modifié
            }

            $hash = $this->userPasswordHasher->hashPassword($form->getData(), $password);
            $form->getData()->setPassword($hash);
        };
    }

    public function createEntity(string $entityFqcn)
    {
        // Tu peux modifier les paramètres selon tes besoins
        return new User();
    }

    public function persistEntity($entityManager, $entityInstance): void
    {
        if (!$entityInstance->getId()) {
            // La création d'un nouvel utilisateur est déjà gérée dans `createEntity()`
        } else {
            // Met à jour les informations de l'utilisateur
            $this->userService->updateUser(
                $entityInstance,
                $entityInstance->getEmail(),
                $entityInstance->getNom(),
                $entityInstance->getPrenom(),
                $entityInstance->getMatricule(),
                null // Si tu veux réinitialiser le mot de passe, passe la nouvelle valeur ici
            );
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    #[Route('/admin/import-users', name: 'admin_import_users')]
    public function importUsers(Request $request): Response
    {
        $form = $this->createFormBuilder()
            ->add('csv_file', FileType::class, ['label' => 'CSV File'])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $csvFile = $form->get('csv_file')->getData();

            if ($csvFile) {
                $newFilename = uniqid() . '.' . $csvFile->guessExtension();

                try {
                    $csvFile->move(
                        $this->getParameter('csv_directory'),
                        $newFilename
                    );

                    $csvFilePath = $this->getParameter('csv_directory') . '/' . $newFilename;
                    $this->userService->importUsersFromCsv($csvFilePath);

                    $this->addFlash('success', 'Users imported successfully.');

                    return $this->redirect($this->adminUrlGenerator->setController(self::class)->generateUrl());
                } catch (FileException $e) {
                    $this->addFlash('danger', 'Error uploading file.');
                }
            }
        }

        return $this->render('admin/import_users.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
